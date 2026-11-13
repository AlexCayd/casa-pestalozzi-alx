<?php
declare(strict_types=1);
namespace Services\Inventory;

/**
 * Proveedores por ámbito (arquitectura §17). Cada proveedor pertenece a un solo
 * ámbito —Global, Restaurante o Panadería— y sólo ese ámbito lo edita, le asigna
 * artículos o le liga entradas. Global además lee el directorio de los tres, como
 * lee los almacenes ajenos: consulta, nunca escritura.
 *
 * El historial de recepciones sale de las entradas que el motor ya registró
 * (entradas_proveedor → operación → movimientos), así que lo pedido coincide con lo
 * recibido. El precio de referencia ofrecido se conserva aparte en
 * historial_precios_proveedores.
 */
final class DirectorioProveedores
{
    private AlcanceInventario $alcance;

    /** Ámbitos que Global consolida; orden de presentación. */
    private const AMBITOS = ['global' => 1, 'restaurante' => 2, 'panaderia' => 3];

    public function __construct(private \mysqli $db, int $actorId, string $nivelAuth)
    {
        $this->alcance = new AlcanceInventario($db, $actorId, $nivelAuth);
    }

    /**
     * Directorio del ámbito. Con $consolidado (sólo Global) trae también los de los
     * negocios, de lectura; cada fila dice de quién es y si se puede editar aquí.
     */
    public function listar(string $ctx, bool $consolidado = false): array
    {
        $alcance = $this->alcance->autorizar($ctx, 'ver');
        $unidades = $consolidado && $ctx === 'global' ? array_values(self::AMBITOS) : [$alcance['unidad_id']];
        $marcas = implode(',', array_fill(0, count($unidades), '?'));
        // Recepciones = días distintos con entradas: una entrega de varios artículos se
        // captura como varias entradas, pero fue un solo pedido.
        $filas = $this->db->execute_query(
            "SELECT p.id,p.unidad_id,u.slug,p.nombre,p.contacto,p.telefono,p.correo,p.activo,
                    (SELECT COUNT(*) FROM ingrediente_proveedores ip WHERE ip.proveedor_id=p.id AND ip.unidad_id=p.unidad_id) AS articulos,
                    (SELECT COUNT(DISTINCT DATE(o.fecha)) FROM entradas_proveedor e JOIN operaciones_inventario o ON o.id=e.operacion_id AND o.unidad_id=e.unidad_id WHERE e.proveedor_id=p.id AND e.unidad_id=p.unidad_id) AS recepciones,
                    (SELECT MAX(o.fecha) FROM entradas_proveedor e JOIN operaciones_inventario o ON o.id=e.operacion_id AND o.unidad_id=e.unidad_id WHERE e.proveedor_id=p.id AND e.unidad_id=p.unidad_id) AS ultima_entrada
               FROM proveedores p JOIN unidades u ON u.id=p.unidad_id
              WHERE p.unidad_id IN ($marcas)
              ORDER BY p.activo DESC, p.unidad_id, p.nombre, p.id",
            $unidades
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($filas as &$f) {
            $f['editable'] = (int)$f['unidad_id'] === $alcance['unidad_id'];
        }
        return $filas;
    }

    /** Un proveedor visible para el ámbito; lanza si es ajeno o no existe. */
    public function proveedor(string $ctx, int $id): array
    {
        $alcance = $this->alcance->autorizar($ctx, 'ver');
        $p = $this->db->execute_query(
            'SELECT p.*,u.slug FROM proveedores p JOIN unidades u ON u.id=p.unidad_id WHERE p.id=?',
            [$id]
        )->fetch_assoc();
        // Un ID ajeno responde igual que uno inexistente: no confirma que exista.
        if (!$p || ((int)$p['unidad_id'] !== $alcance['unidad_id'] && $ctx !== 'global')) {
            throw new InventarioError('RECURSO_NO_ENCONTRADO', 'No se encontró el proveedor.');
        }
        $p['editable'] = (int)$p['unidad_id'] === $alcance['unidad_id'];
        return $p;
    }

    /** Alta (sin $id) o edición de los datos de contacto de un proveedor propio. */
    public function guardar(string $ctx, ?int $id, array $datos): array
    {
        return TransaccionInventario::ejecutar($this->db, function () use ($ctx, $id, $datos): array {
            $alcance = $this->alcance->autorizar($ctx, 'editar', null, true);
            $u = $alcance['unidad_id'];
            $d = self::datosProveedor($datos);
            if ($id !== null) $this->propio($u, $id, true);
            $choque = $this->db->execute_query(
                'SELECT id FROM proveedores WHERE unidad_id=? AND nombre=? AND id<>? LIMIT 1',
                [$u, $d['nombre'], $id ?? 0]
            )->fetch_row();
            if ($choque) throw new InventarioError('PROVEEDOR_DUPLICADO', 'Ya existe un proveedor con ese nombre en este ámbito.');
            if ($id === null) {
                $this->db->execute_query(
                    'INSERT INTO proveedores(unidad_id,nombre,contacto,telefono,correo,notas,activo) VALUES (?,?,?,?,?,?,?)',
                    [$u, $d['nombre'], $d['contacto'], $d['telefono'], $d['correo'], $d['notas'], $d['activo']]
                );
                $id = (int)$this->db->insert_id;
            } else {
                $this->db->execute_query(
                    'UPDATE proveedores SET nombre=?,contacto=?,telefono=?,correo=?,notas=?,activo=? WHERE id=? AND unidad_id=?',
                    [$d['nombre'], $d['contacto'], $d['telefono'], $d['correo'], $d['notas'], $d['activo'], $id, $u]
                );
            }
            return ['codigo' => 'PROVEEDOR_GUARDADO', 'proveedor_id' => $id];
        });
    }

    /**
     * Reemplaza en bloque los artículos que surte un proveedor propio. El formulario
     * manda la lista completa: quitar una fila allí la quita aquí. Sólo se aceptan
     * artículos que el ámbito tiene en alguno de sus almacenes, y marcar preferente
     * desmarca al preferente anterior de ese artículo (uno por artículo y ámbito).
     *
     * $filas: [['articulo_id'=>'12','costo'=>'0.0150','codigo'=>'','preferente'=>'1'], …]
     */
    public function guardarAsignaciones(string $ctx, int $id, array $filas): array
    {
        if (!$this->historialPreciosDisponible()) return ['ok'=>false,'confirmado'=>false,'codigo'=>'MIGRACION_PENDIENTE','mensaje'=>'El historial de precios aún no está instalado. Aplica la migración etapa1-precios-proveedores.sql antes de editar referencias.'];
        return TransaccionInventario::ejecutar($this->db, function () use ($ctx, $id, $filas): array {
            $alcance = $this->alcance->autorizar($ctx, 'editar', null, true);
            $u = $alcance['unidad_id'];
            $this->propio($u, $id, true);
            $this->exigirHistorialPreciosDisponible();
            if (!array_is_list($filas) || count($filas) > 500) throw new InventarioError('DATOS_INVALIDOS', 'La lista de artículos no es válida.');
            $limpias = [];
            foreach ($filas as $fila) {
                if (!is_array($fila) || array_diff(array_keys($fila), ['articulo_id', 'costo', 'codigo', 'preferente'])) throw new InventarioError('DATOS_INVALIDOS', 'La lista de artículos no es válida.');
                $articulo = self::id($fila['articulo_id'] ?? null);
                if (isset($limpias[$articulo])) throw new InventarioError('ARTICULO_REPETIDO', 'Un artículo aparece dos veces en la lista.');
                $codigo = trim(is_string($fila['codigo'] ?? null) ? $fila['codigo'] : '');
                if (mb_strlen($codigo) > 60) throw new InventarioError('DATOS_INVALIDOS', 'El código del proveedor admite hasta 60 caracteres.');
                $limpias[$articulo] = [
                    'costo' => DecimalInventario::formato(DecimalInventario::leer($fila['costo'] ?? null, 4), 4),
                    'codigo' => $codigo === '' ? null : $codigo,
                    'preferente' => in_array($fila['preferente'] ?? null, ['1', 1, true], true) ? 1 : 0,
                ];
            }
            if ($limpias) {
                $marcas = implode(',', array_fill(0, count($limpias), '?'));
                $propios = $this->db->execute_query(
                    "SELECT DISTINCT aa.articulo_id FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id
                      WHERE aa.unidad_id=? AND aa.articulo_id IN ($marcas) FOR SHARE",
                    array_merge([$u], array_keys($limpias))
                )->fetch_all(MYSQLI_NUM);
                if (count($propios) !== count($limpias)) throw new InventarioError('ARTICULO_AJENO', 'Sólo se asignan artículos que este ámbito tiene en sus almacenes.');
            }
            // El bloqueo de proveedor serializa reconciliaciones concurrentes del
            // conjunto completo; así el precio anterior siempre parte del último
            // evento confirmado, nunca de una lectura obsoleta.
            $proveedor = $this->db->execute_query('SELECT nombre FROM proveedores WHERE id=? AND unidad_id=? FOR UPDATE', [$id, $u])->fetch_assoc();
            $actuales = $this->db->execute_query(
                'SELECT ingrediente_id,costo,codigo,preferente FROM ingrediente_proveedores WHERE unidad_id=? AND proveedor_id=? FOR UPDATE',
                [$u, $id]
            )->fetch_all(MYSQLI_ASSOC);
            $porId = [];
            foreach ($actuales as $actual) $porId[(int)$actual['ingrediente_id']] = $actual;
            $usuario = $this->db->execute_query('SELECT nombre FROM usuarios WHERE id=?', [$this->alcance->actorId()])->fetch_row();
            $usuarioNombre = (string)($usuario[0] ?? 'Usuario eliminado');
            // El primer guardado post-migración observa todas las relaciones antiguas,
            // también las que se retiran en este mismo envío.
            foreach ($porId as $articuloExistente => $filaExistente) {
                $artExistente = $this->db->execute_query('SELECT nombre,unidad FROM ingredientes WHERE id=?', [$articuloExistente])->fetch_assoc();
                $ultimoExistente = $this->db->execute_query(
                    'SELECT precio_nuevo,unidad_base_snapshot FROM historial_precios_proveedores WHERE unidad_id=? AND proveedor_id=? AND articulo_id=? ORDER BY created_at DESC,id DESC LIMIT 1',
                    [$u, $id, $articuloExistente]
                )->fetch_row();
                if ($ultimoExistente === null || (string)$ultimoExistente[1] !== (string)$artExistente['unidad']) {
                    // Un cambio de unidad inicia una base comparable nueva: no se
                    // enlazan precios por pieza con precios por gramo, por ejemplo.
                    $this->registrarPrecio($u, $id, $articuloExistente, (string)$proveedor['nombre'], (string)$artExistente['nombre'], (string)$artExistente['unidad'], null, (string)$filaExistente['costo'], 'base_observada', $usuarioNombre);
                }
            }
            foreach ($limpias as $articulo => $f) {
                $anterior = $porId[$articulo] ?? null;
                $art = $this->db->execute_query('SELECT nombre,unidad FROM ingredientes WHERE id=?', [$articulo])->fetch_assoc();
                $ultimo = $this->db->execute_query(
                    'SELECT precio_nuevo,unidad_base_snapshot FROM historial_precios_proveedores WHERE unidad_id=? AND proveedor_id=? AND articulo_id=? ORDER BY created_at DESC,id DESC LIMIT 1',
                    [$u, $id, $articulo]
                )->fetch_row();
                if ($ultimo !== null && (string)$ultimo[1] === (string)$art['unidad']) {
                    $precioRegistrado = (string)$ultimo[0];
                } elseif ($anterior !== null) {
                    $this->registrarPrecio($u, $id, $articulo, (string)$proveedor['nombre'], (string)$art['nombre'], (string)$art['unidad'], null, (string)$anterior['costo'], 'base_observada', $usuarioNombre);
                    $precioRegistrado = (string)$anterior['costo'];
                } else {
                    // Pareja inédita o artículo con unidad nueva: nunca comparar
                    // contra un precio que corresponda a otra unidad base.
                    $tipo = $ultimo === null ? 'inicial' : 'base_observada';
                    $this->registrarPrecio($u, $id, $articulo, (string)$proveedor['nombre'], (string)$art['nombre'], (string)$art['unidad'], null, $f['costo'], $tipo, $usuarioNombre);
                    $precioRegistrado = $f['costo'];
                }
                if ($precioRegistrado !== $f['costo']) {
                    $this->registrarPrecio($u, $id, $articulo, (string)$proveedor['nombre'], (string)$art['nombre'], (string)$art['unidad'], $precioRegistrado, $f['costo'], 'cambio', $usuarioNombre);
                }
                if ($f['preferente']) {
                    $this->db->execute_query('UPDATE ingrediente_proveedores SET preferente=0 WHERE unidad_id=? AND ingrediente_id=? AND preferente=1', [$u, $articulo]);
                }
                if ($anterior === null) {
                    $this->db->execute_query(
                        'INSERT INTO ingrediente_proveedores(unidad_id,ingrediente_id,proveedor_id,costo,codigo,preferente) VALUES (?,?,?,?,?,?)',
                        [$u, $articulo, $id, $f['costo'], $f['codigo'], $f['preferente']]
                    );
                } else {
                    $this->db->execute_query(
                        'UPDATE ingrediente_proveedores SET costo=?,codigo=?,preferente=? WHERE unidad_id=? AND proveedor_id=? AND ingrediente_id=?',
                        [$f['costo'], $f['codigo'], $f['preferente'], $u, $id, $articulo]
                    );
                }
                unset($porId[$articulo]);
            }
            foreach (array_keys($porId) as $articulo) {
                $this->db->execute_query('DELETE FROM ingrediente_proveedores WHERE unidad_id=? AND proveedor_id=? AND ingrediente_id=?', [$u, $id, $articulo]);
            }
            return ['codigo' => 'ASIGNACIONES_GUARDADAS', 'proveedor_id' => $id, 'articulos' => count($limpias)];
        });
    }

    /**
     * Sólo sin historial: un proveedor que ya surtió algo se desactiva, para que sus
     * entradas sigan diciendo de quién llegaron.
     */
    public function eliminar(string $ctx, int $id): array
    {
        return TransaccionInventario::ejecutar($this->db, function () use ($ctx, $id): array {
            $alcance = $this->alcance->autorizar($ctx, 'editar', null, true);
            $u = $alcance['unidad_id'];
            $this->propio($u, $id, true);
            $this->exigirHistorialPreciosDisponible();
            $con = (int)$this->db->execute_query('SELECT COUNT(*) FROM entradas_proveedor WHERE unidad_id=? AND proveedor_id=?', [$u, $id])->fetch_row()[0];
            if ($con > 0) throw new InventarioError('PROVEEDOR_CON_HISTORIAL', 'Este proveedor ya tiene entradas registradas. Desactívalo para conservar su historial.');
            $precios = (int)$this->db->execute_query('SELECT COUNT(*) FROM historial_precios_proveedores WHERE unidad_id=? AND proveedor_id=?', [$u, $id])->fetch_row()[0];
            if ($precios > 0) throw new InventarioError('PROVEEDOR_CON_HISTORIAL', 'Este proveedor ya tiene historial de precios. Desactívalo para conservar su trazabilidad.');
            $this->db->execute_query('DELETE FROM ingrediente_proveedores WHERE unidad_id=? AND proveedor_id=?', [$u, $id]);
            $this->db->execute_query('DELETE FROM proveedores WHERE id=? AND unidad_id=?', [$id, $u]);
            return ['codigo' => 'PROVEEDOR_ELIMINADO', 'proveedor_id' => $id];
        });
    }

    /** Artículos que surte, con su costo de referencia contra el costo maestro. */
    public function asignaciones(string $ctx, int $id): array
    {
        $p = $this->proveedor($ctx, $id);
        return $this->db->execute_query(
            'SELECT ip.ingrediente_id AS articulo_id,i.nombre,i.unidad,i.costo AS costo_maestro,i.activo,ip.costo,ip.codigo,ip.preferente
               FROM ingrediente_proveedores ip JOIN ingredientes i ON i.id=ip.ingrediente_id
              WHERE ip.unidad_id=? AND ip.proveedor_id=? ORDER BY i.nombre,i.id',
            [(int)$p['unidad_id'], $id]
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** Historial paginado del precio de referencia. La asociación vigente es opcional. */
    public function historialPrecios(string $ctx, int $id, int $pagina = 1, ?int $articuloId = null): array
    {
        $this->exigirHistorialPreciosDisponible();
        $p = $this->proveedor($ctx, $id);
        $pagina = max(1, $pagina);
        $limite = 25;
        $where = 'unidad_id=? AND proveedor_id=?';
        $params = [(int)$p['unidad_id'], $id];
        if ($articuloId !== null) { $where .= ' AND articulo_id=?'; $params[] = $articuloId; }
        $total = (int)$this->db->execute_query("SELECT COUNT(*) FROM historial_precios_proveedores WHERE $where", $params)->fetch_row()[0];
        $paginas = max(1, (int)ceil($total / $limite));
        $pagina = min($pagina, $paginas);
        $offset = ($pagina - 1) * $limite;
        $rows = $this->db->execute_query(
            "SELECT h.articulo_id,h.articulo_nombre_snapshot AS articulo,h.unidad_base_snapshot AS unidad,
                    h.precio_anterior,h.precio_nuevo,h.tipo,h.created_at,h.usuario_nombre_snapshot AS responsable,
                    ip.costo AS precio_actual,i.unidad AS unidad_actual
               FROM historial_precios_proveedores h
               LEFT JOIN ingrediente_proveedores ip ON ip.unidad_id=h.unidad_id AND ip.proveedor_id=h.proveedor_id AND ip.ingrediente_id=h.articulo_id
               LEFT JOIN ingredientes i ON i.id=h.articulo_id
              WHERE h.unidad_id=? AND h.proveedor_id=?" . ($articuloId !== null ? ' AND h.articulo_id=?' : '') .
            ' ORDER BY h.created_at DESC,h.id DESC LIMIT ' . $limite . ' OFFSET ' . $offset,
            $params
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$row) {
            $row['precio_anterior'] = $row['precio_anterior'] === null ? null : DecimalInventario::formato(DecimalInventario::leer((string)$row['precio_anterior'], 4), 4);
            $row['precio_nuevo'] = DecimalInventario::formato(DecimalInventario::leer((string)$row['precio_nuevo'], 4), 4);
            $row['precio_actual'] = $row['precio_actual'] === null ? null : DecimalInventario::formato(DecimalInventario::leer((string)$row['precio_actual'], 4), 4);
            $row['diferencia'] = $row['precio_anterior'] === null ? null : DecimalInventario::formato(DecimalInventario::leer($row['precio_nuevo'], 4) - DecimalInventario::leer($row['precio_anterior'], 4), 4);
            $a = $row['precio_anterior'] === null ? null : DecimalInventario::leer($row['precio_anterior'], 4);
            $n = DecimalInventario::leer($row['precio_nuevo'], 4);
            $row['variacion_pct'] = $a === null || $a === 0 ? null : round(($n - $a) * 100 / $a, 2);
        }
        unset($row);
        return ['filas' => $rows, 'pagina' => $pagina, 'paginas' => $paginas, 'total' => $total, 'limite' => $limite];
    }

    /** Comparación de precio vigente del mismo artículo; solo proveedores del ámbito consultado. */
    public function compararPrecios(string $ctx, int $id, int $articuloId): array
    {
        $this->exigirHistorialPreciosDisponible();
        $p = $this->proveedor($ctx, $id);
        return $this->db->execute_query(
            'SELECT ip.proveedor_id,p.nombre,p.unidad_id,p.activo,ip.costo AS precio_referencia,ip.preferente,i.nombre AS articulo,i.unidad
               FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id
               JOIN ingredientes i ON i.id=ip.ingrediente_id
              WHERE ip.unidad_id=? AND ip.ingrediente_id=? ORDER BY ip.costo,p.nombre,p.id',
            [(int)$p['unidad_id'], $articuloId]
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** Artículos con cualquier evento, incluso si ya se retiró su asociación. */
    public function articulosHistorialPrecios(string $ctx, int $id): array
    {
        $this->exigirHistorialPreciosDisponible();
        $p = $this->proveedor($ctx, $id);
        $historicos = $this->db->execute_query(
            'SELECT h.articulo_id,
                    (SELECT x.articulo_nombre_snapshot FROM historial_precios_proveedores x WHERE x.unidad_id=h.unidad_id AND x.proveedor_id=h.proveedor_id AND x.articulo_id=h.articulo_id ORDER BY x.created_at DESC,x.id DESC LIMIT 1) AS nombre,
                    (SELECT x.unidad_base_snapshot FROM historial_precios_proveedores x WHERE x.unidad_id=h.unidad_id AND x.proveedor_id=h.proveedor_id AND x.articulo_id=h.articulo_id ORDER BY x.created_at DESC,x.id DESC LIMIT 1) AS unidad
               FROM historial_precios_proveedores h WHERE h.unidad_id=? AND h.proveedor_id=?
              GROUP BY h.unidad_id,h.proveedor_id,h.articulo_id ORDER BY nombre,h.articulo_id',
            [(int)$p['unidad_id'], $id]
        )->fetch_all(MYSQLI_ASSOC);
        $actuales = $this->db->execute_query(
            'SELECT ip.ingrediente_id AS articulo_id,i.nombre,i.unidad FROM ingrediente_proveedores ip JOIN ingredientes i ON i.id=ip.ingrediente_id WHERE ip.unidad_id=? AND ip.proveedor_id=?',
            [(int)$p['unidad_id'], $id]
        )->fetch_all(MYSQLI_ASSOC);
        $porArticulo = [];
        foreach ($historicos as $fila) $porArticulo[(int)$fila['articulo_id']] = $fila;
        foreach ($actuales as $fila) $porArticulo[(int)$fila['articulo_id']] = $fila;
        $salida = array_values($porArticulo);
        usort($salida, static fn($a,$b)=>strcmp((string)$a['nombre'],(string)$b['nombre']) ?: ((int)$a['articulo_id'] <=> (int)$b['articulo_id']));
        return $salida;
    }

    /** Serie para la gráfica; solo devuelve datos con al menos tres eventos. */
    public function graficaPrecio(string $ctx, int $id, int $articuloId): ?array
    {
        $this->exigirHistorialPreciosDisponible();
        $p = $this->proveedor($ctx, $id);
        $rows = $this->db->execute_query(
            'SELECT precio_nuevo,unidad_base_snapshot,created_at,tipo FROM historial_precios_proveedores
              WHERE unidad_id=? AND proveedor_id=? AND articulo_id=? ORDER BY created_at DESC,id DESC LIMIT 60',
            [(int)$p['unidad_id'], $id, $articuloId]
        )->fetch_all(MYSQLI_ASSOC);
        if (count($rows) < 3) return null;
        $unidad = (string)$rows[0]['unidad_base_snapshot'];
        $serie = [];
        foreach ($rows as $row) {
            if ((string)$row['unidad_base_snapshot'] !== $unidad) break;
            $serie[] = $row;
            // Esta base abre un tramo comparable independiente; no unir con puntos
            // anteriores aunque el artículo haya vuelto a esa misma unidad años atrás.
            if ($row['tipo'] === 'base_observada') break;
        }
        $rows = $serie;
        if (count($rows) < 3) return null;
        $rows = array_reverse($rows);
        $valores = array_map(static fn($r) => DecimalInventario::leer((string)$r['precio_nuevo'], 4), $rows);
        $min = min($valores); $max = max($valores); $rango = max(1, $max - $min);
        $coords = [];
        foreach ($valores as $i => $v) {
            $x = count($valores) === 1 ? 50 : round(8 + 84 * $i / (count($valores) - 1), 2);
            $y = round(48 - 40 * ($v - $min) / $rango, 2);
            $coords[] = $x . ',' . $y;
        }
        return ['unidad' => $unidad, 'puntos' => implode(' ', $coords), 'minimo' => DecimalInventario::formato($min, 4), 'maximo' => DecimalInventario::formato($max, 4), 'eventos' => count($rows), 'desde' => substr((string)$rows[0]['created_at'], 0, 10), 'hasta' => substr((string)$rows[count($rows) - 1]['created_at'], 0, 10)];
    }

    private function registrarPrecio(int $unidad, int $proveedor, int $articulo, string $proveedorNombre, string $articuloNombre, string $unidadBase, ?string $anterior, string $nuevo, string $tipo, string $usuarioNombre): void
    {
        $this->db->execute_query(
            'INSERT INTO historial_precios_proveedores(unidad_id,proveedor_id,articulo_id,proveedor_nombre_snapshot,articulo_nombre_snapshot,unidad_base_snapshot,precio_anterior,precio_nuevo,tipo,usuario_id,usuario_nombre_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$unidad, $proveedor, $articulo, $proveedorNombre, $articuloNombre, $unidadBase, $anterior, $nuevo, $tipo, $this->alcance->actorId(), $usuarioNombre]
        );
    }

    private function historialPreciosDisponible(): bool
    {
        return (int)$this->db->execute_query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='historial_precios_proveedores'")->fetch_row()[0] === 1;
    }

    private function exigirHistorialPreciosDisponible(): void
    {
        if (!$this->historialPreciosDisponible()) throw new InventarioError('MIGRACION_PENDIENTE', 'El historial de precios aún no está instalado. Aplica la migración etapa1-precios-proveedores.sql.');
    }

    /** Artículos que el ámbito tiene en sus almacenes: lo único que se le puede asignar. */
    public function articulosAsignables(string $ctx): array
    {
        $alcance = $this->alcance->autorizar($ctx, 'ver');
        return $this->db->execute_query(
            'SELECT DISTINCT i.id,i.nombre,i.unidad,i.costo FROM almacen_articulos aa
               JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id
               JOIN ingredientes i ON i.id=aa.articulo_id
              WHERE aa.unidad_id=? AND i.activo=1 ORDER BY i.nombre,i.id',
            [$alcance['unidad_id']]
        )->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Lo que necesita el diálogo de Entrada: proveedores activos del ámbito y, por
     * artículo, quién lo surte y a qué costo de referencia (preferente primero).
     *
     * @return array{proveedores: list<array>, porArticulo: array<int, list<array>>}
     */
    public function paraEntradas(string $ctx): array
    {
        $alcance = $this->alcance->autorizar($ctx, 'ver');
        $u = $alcance['unidad_id'];
        $proveedores = $this->db->execute_query('SELECT id,nombre FROM proveedores WHERE unidad_id=? AND activo=1 ORDER BY nombre,id', [$u])->fetch_all(MYSQLI_ASSOC);
        $porArticulo = [];
        $filas = $this->db->execute_query(
            'SELECT ip.ingrediente_id,ip.proveedor_id,ip.costo,ip.preferente FROM ingrediente_proveedores ip
               JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id
              WHERE ip.unidad_id=? AND p.activo=1 ORDER BY ip.preferente DESC,ip.costo,p.nombre',
            [$u]
        )->fetch_all(MYSQLI_ASSOC);
        foreach ($filas as $f) $porArticulo[(int)$f['ingrediente_id']][] = $f;
        return ['proveedores' => $proveedores, 'porArticulo' => $porArticulo];
    }

    /**
     * Histórico de lo recibido. Una recepción es un día con entradas de este
     * proveedor. Las cifras se agregan en enteros exactos (milésimas y diezmilésimas);
     * sólo la variación porcentual y el intervalo en días son de lectura.
     */
    public function historial(string $ctx, int $id): array
    {
        $p = $this->proveedor($ctx, $id);
        // Tope de lectura: suficiente para años de compras de un negocio; una página
        // no debe recorrer una bitácora sin límite.
        $movs = $this->db->execute_query(
            'SELECT m.id,m.articulo_id,i.nombre,i.unidad,m.delta,m.costo_unitario,m.nota,o.fecha,a.nombre AS almacen
               FROM entradas_proveedor e
               JOIN operaciones_inventario o ON o.id=e.operacion_id AND o.unidad_id=e.unidad_id
               JOIN movimientos_inventario m ON m.operacion_id=o.id AND m.unidad_id=o.unidad_id AND m.tipo=\'entrada\'
               JOIN ingredientes i ON i.id=m.articulo_id
               JOIN almacenes a ON a.id=m.almacen_id AND a.unidad_id=m.unidad_id
              WHERE e.unidad_id=? AND e.proveedor_id=?
              ORDER BY o.fecha,m.id LIMIT 5000',
            [(int)$p['unidad_id'], $id]
        )->fetch_all(MYSQLI_ASSOC);

        $dias = [];
        $articulos = [];
        foreach ($movs as $m) {
            $dia = substr((string)$m['fecha'], 0, 10);
            $dias[$dia] = true;
            $aid = (int)$m['articulo_id'];
            $q = DecimalInventario::leer((string)$m['delta'], 3);
            $c = $m['costo_unitario'] === null ? null : DecimalInventario::leer((string)$m['costo_unitario'], 4);
            $a = &$articulos[$aid];
            $a ??= ['articulo_id' => $aid, 'nombre' => $m['nombre'], 'unidad' => $m['unidad'], 'dias' => [], 'total' => 0, 'ultima' => null, 'costos' => []];
            $a['dias'][$dia] = true;
            $a['total'] = DecimalInventario::sumar($a['total'], $q);
            $a['ultima'] = (string)$m['fecha'];
            if ($c !== null) $a['costos'][] = ['fecha' => (string)$m['fecha'], 'costo' => $c];
            unset($a);
        }

        $salida = [];
        foreach ($articulos as $a) {
            $veces = count($a['dias']);
            $costos = array_column($a['costos'], 'costo');
            $primero = $costos[0] ?? null;
            $ultimo = $costos ? $costos[count($costos) - 1] : null;
            $salida[] = [
                'articulo_id' => $a['articulo_id'], 'nombre' => $a['nombre'], 'unidad' => $a['unidad'],
                'veces' => $veces,
                'total' => DecimalInventario::cantidad($a['total']),
                // Promedio por recepción, redondeado a milésimas (mitad hacia arriba).
                'promedio' => DecimalInventario::cantidad(intdiv(2 * $a['total'] + $veces, 2 * $veces)),
                'ultima' => $a['ultima'],
                'costo_ultimo' => $ultimo === null ? null : DecimalInventario::formato($ultimo, 4),
                'costo_primero' => $primero === null ? null : DecimalInventario::formato($primero, 4),
                'costo_min' => $costos ? DecimalInventario::formato(min($costos), 4) : null,
                'costo_max' => $costos ? DecimalInventario::formato(max($costos), 4) : null,
                'variacion' => ($primero !== null && $primero > 0 && $ultimo !== null) ? round(($ultimo - $primero) * 100 / $primero, 1) : null,
                // Los últimos doce costos, del más reciente al más antiguo, para leer la tendencia.
                'serie' => array_map(static fn($x) => ['fecha' => $x['fecha'], 'costo' => DecimalInventario::formato($x['costo'], 4)], array_reverse(array_slice($a['costos'], -12))),
            ];
        }
        // Lo que más se le pide arriba: más recepciones, luego más cantidad.
        usort($salida, static fn($x, $y) => [$y['veces'], DecimalInventario::leer($y['total'])] <=> [$x['veces'], DecimalInventario::leer($x['total'])] ?: strcmp($x['nombre'], $y['nombre']));

        $fechas = array_keys($dias);
        sort($fechas);
        $n = count($fechas);
        $intervalo = null;
        $proximo = null;
        if ($n >= 2) {
            $primera = new \DateTimeImmutable($fechas[0]);
            $ultima = new \DateTimeImmutable($fechas[$n - 1]);
            $intervalo = round((int)$primera->diff($ultima)->days / ($n - 1), 1);
            $proximo = $ultima->modify('+' . (int)round($intervalo) . ' days')->format('Y-m-d');
        }

        return [
            'resumen' => ['recepciones' => $n, 'primera' => $fechas[0] ?? null, 'ultima' => $n ? $fechas[$n - 1] : null, 'intervalo_dias' => $intervalo, 'proximo' => $proximo],
            'articulos' => $salida,
            'recientes' => array_map(static fn($m) => [
                'fecha' => (string)$m['fecha'], 'almacen' => $m['almacen'], 'nombre' => $m['nombre'], 'unidad' => $m['unidad'],
                'cantidad' => (string)$m['delta'], 'costo' => $m['costo_unitario'], 'nota' => $m['nota'],
            ], array_slice(array_reverse($movs), 0, 15)),
        ];
    }

    /**
     * Valida, dentro de la transacción de la entrada, que el proveedor sea del ámbito
     * y siga activo. FOR SHARE: nadie lo desactiva ni lo borra mientras se liga.
     */
    public static function exigirActivo(\mysqli $db, int $unidad, int $proveedor): void
    {
        $r = $db->execute_query('SELECT activo FROM proveedores WHERE id=? AND unidad_id=? FOR SHARE', [$proveedor, $unidad])->fetch_row();
        if (!$r) throw new InventarioError('PROVEEDOR_INVALIDO', 'El proveedor no pertenece a este ámbito.');
        if (!(int)$r[0]) throw new InventarioError('PROVEEDOR_INACTIVO', 'El proveedor está inactivo.');
    }

    /**
     * Liga la operación de entrada con su proveedor. Un reintento con la misma clave
     * repite el resultado del motor: si ya está ligada al mismo proveedor no hay nada
     * que hacer; a otro, es una solicitud distinta con la misma clave.
     */
    public static function ligar(\mysqli $db, int $unidad, int $operacion, int $proveedor): void
    {
        $previo = $db->execute_query('SELECT proveedor_id FROM entradas_proveedor WHERE operacion_id=? FOR UPDATE', [$operacion])->fetch_row();
        if ($previo) {
            if ((int)$previo[0] !== $proveedor) throw new InventarioError('CONFLICTO_IDEMPOTENCIA', 'La entrada ya se registró con otro proveedor.');
            return;
        }
        $db->execute_query('INSERT INTO entradas_proveedor(operacion_id,unidad_id,proveedor_id) VALUES (?,?,?)', [$operacion, $unidad, $proveedor]);
    }

    private function propio(int $unidad, int $id, bool $bloquear): void
    {
        $r = $this->db->execute_query('SELECT id FROM proveedores WHERE id=? AND unidad_id=?' . ($bloquear ? ' FOR UPDATE' : ''), [$id, $unidad])->fetch_row();
        if (!$r) throw new InventarioError('RECURSO_NO_ENCONTRADO', 'No se encontró el proveedor de este ámbito.');
    }

    private static function datosProveedor(array $datos): array
    {
        $texto = static function (string $campo, int $max, bool $obligatorio = false) use ($datos): ?string {
            $v = $datos[$campo] ?? '';
            if (!is_string($v)) throw new InventarioError('DATOS_INVALIDOS', 'Revisa los datos del proveedor.');
            $v = trim($v);
            if ($obligatorio && $v === '') throw new InventarioError('DATOS_INVALIDOS', 'El nombre del proveedor es obligatorio.');
            if (mb_strlen($v) > $max) throw new InventarioError('DATOS_INVALIDOS', 'Un campo del proveedor excede su longitud.');
            return $v === '' ? null : $v;
        };
        $correo = $texto('correo', 120);
        if ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new InventarioError('CORREO_INVALIDO', 'El correo del proveedor no es válido.');
        return [
            'nombre' => (string)$texto('nombre', 120, true), 'contacto' => $texto('contacto', 120), 'telefono' => $texto('telefono', 30),
            'correo' => $correo, 'notas' => $texto('notas', 2000), 'activo' => isset($datos['activo']) && $datos['activo'] !== '0' ? 1 : 0,
        ];
    }

    private static function id(mixed $v): int
    {
        if ((!is_int($v) && !is_string($v)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string)$v)) throw new InventarioError('ID_INVALIDO', 'El identificador no es válido.');
        return (int)$v;
    }
}
