<?php

namespace Services\Configuration;

use Model\ActiveRecord;
use Services\Inventory\InventarioError;
use Services\Security\ContextoNegocio;

/**
 * Avisos del restaurante en las cartas PDF (Configuración → Avisos de la carta).
 *
 * Un aviso es una nota de la casa —alergias, formas de pago, lo que depende
 * del mercado— con título y contenido, que se imprime en la carta de comida,
 * en la de maridaje o en las dos, al inicio (después de la portada) o al
 * final (antes de la despedida). Dos lugares y no uno porque son dos clases
 * de aviso: lo que hay que saber ANTES de leer la carta («la cocina cierra a
 * las 22:00») y la letra de cierre («la propina es voluntaria»).
 *
 * El contenido se guarda como marcado ligero, nunca como HTML:
 *   · un renglón que empieza con `## ` es un subtítulo;
 *   · `**texto**` va en negritas (dentro de un mismo renglón);
 *   · un renglón en blanco abre un párrafo nuevo y un salto simple es un
 *     salto de línea.
 * Todo lo demás se escapa. Un `**` sin pareja se imprime tal cual, en vez de
 * poner en negritas el resto del aviso. html() es la ÚNICA traducción a HTML:
 * la usan el PDF y la vista previa del panel, y el editor (avisos.js) repite
 * estas mismas reglas para la vista previa en vivo.
 *
 * Sólo Restaurante: la Panadería no tiene Configuración ni estos avisos.
 * La lectura para el PDF es tolerante —sin la tabla (instalación sin migrar)
 * la carta sale igual, sin avisos—; las escrituras exigen
 * configuration/editar de Restaurante dentro de la transacción.
 */
final class AvisosCarta
{
    public const MAX_AVISOS = 12;
    public const MAX_TITULO = 80;
    public const MAX_CONTENIDO = 1500;
    public const MAX_RENGLONES = 40;

    /** Cartas PDF de Restaurante donde puede imprimirse un aviso (clave => columna). */
    public const CARTAS = [
        'comida' => ['columna' => 'en_comida', 'titulo' => 'Menú', 'ayuda' => 'La carta de comida'],
        'maridaje' => ['columna' => 'en_maridaje', 'titulo' => 'Maridaje', 'ayuda' => 'Bebidas y coctelería'],
    ];

    public const POSICIONES = [
        'inicio' => ['titulo' => 'Al inicio', 'ayuda' => 'Después de la portada, antes de la primera sección'],
        'final' => ['titulo' => 'Al final', 'ayuda' => 'Después de la última sección, antes de la despedida'],
    ];

    /** Arranques del editor. Texto de ejemplo: se ajusta antes de guardar. */
    public const PLANTILLAS = [
        'alergias' => [
            'nombre' => 'Alergias',
            'titulo' => 'Alergias e intolerancias',
            'contenido' => "Si tienes alguna alergia o intolerancia, **avísanos antes de ordenar**: te decimos qué lleva cada platillo y, cuando se puede, lo preparamos aparte.",
            'posicion' => 'final',
            'cartas' => ['comida'],
        ],
        'pagos' => [
            'nombre' => 'Formas de pago',
            'titulo' => 'Servicio',
            'contenido' => "## Formas de pago\nEfectivo, tarjeta y transferencia.\n\nLa propina es **voluntaria** y no está incluida en la cuenta.",
            'posicion' => 'final',
            'cartas' => ['comida', 'maridaje'],
        ],
        'temporada' => [
            'nombre' => 'Temporada',
            'titulo' => 'De temporada',
            'contenido' => "Algunos platillos dependen de la cosecha y del mercado del día. **Pregúntale a tu mesero** qué hay hoy.",
            'posicion' => 'inicio',
            'cartas' => ['comida'],
        ],
    ];

    // ── Marcado ────────────────────────────────────────────────

    /**
     * El contenido en bloques: ['tipo' => 'h2', 'texto' => string] o
     * ['tipo' => 'p', 'renglones' => list<list<['texto', 'negrita']>>].
     */
    public static function bloques(string $contenido): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $contenido);
        $bloques = [];
        $parrafo = [];
        $cerrar = static function () use (&$bloques, &$parrafo): void {
            if ($parrafo !== []) {
                $bloques[] = ['tipo' => 'p', 'renglones' => $parrafo];
                $parrafo = [];
            }
        };

        foreach (explode("\n", $texto) as $linea) {
            $limpia = trim($linea);
            if ($limpia === '') {
                $cerrar();
                continue;
            }
            // `## ` abre un subtítulo; `###` no (sería otro nivel, que la carta
            // no tiene) y se queda como texto. Las negritas dentro de un
            // subtítulo se ignoran: ya es la voz más fuerte del aviso, y en el
            // PDF la serif no tiene negrita.
            if (preg_match('/^##(?!#)\s*(.*)$/u', $limpia, $m)) {
                $cerrar();
                $titulo = trim(str_replace('**', '', $m[1]));
                if ($titulo !== '') {
                    $bloques[] = ['tipo' => 'h2', 'texto' => $titulo];
                }
                continue;
            }
            $parrafo[] = self::segmentos($limpia);
        }
        $cerrar();

        return $bloques;
    }

    /**
     * HTML del contenido, todo escapado. `$clases` pone clase a h2, p y
     * strong (el PDF y el panel tienen las suyas).
     */
    public static function html(string $contenido, array $clases = []): string
    {
        $clases += ['h2' => '', 'p' => '', 'b' => ''];
        $attr = static fn (string $k): string => $clases[$k] !== '' ? ' class="' . self::e($clases[$k]) . '"' : '';
        $html = '';
        foreach (self::bloques($contenido) as $bloque) {
            if ($bloque['tipo'] === 'h2') {
                $html .= '<h2' . $attr('h2') . '>' . self::e($bloque['texto']) . '</h2>';
                continue;
            }
            $renglones = [];
            foreach ($bloque['renglones'] as $segmentos) {
                $renglon = '';
                foreach ($segmentos as $s) {
                    $renglon .= $s['negrita']
                        ? '<strong' . $attr('b') . '>' . self::e($s['texto']) . '</strong>'
                        : self::e($s['texto']);
                }
                $renglones[] = $renglon;
            }
            $html .= '<p' . $attr('p') . '>' . implode('<br>', $renglones) . '</p>';
        }
        return $html;
    }

    /** Texto corrido sin marcas, para el extracto del listado. */
    public static function extracto(string $contenido, int $largo = 140): string
    {
        $partes = [];
        foreach (self::bloques($contenido) as $bloque) {
            if ($bloque['tipo'] === 'h2') {
                $partes[] = $bloque['texto'];
                continue;
            }
            foreach ($bloque['renglones'] as $segmentos) {
                $partes[] = implode('', array_column($segmentos, 'texto'));
            }
        }
        $texto = trim((string) preg_replace('/\s+/u', ' ', implode(' · ', $partes)));
        return mb_strlen($texto) > $largo ? rtrim(mb_substr($texto, 0, $largo - 1)) . '…' : $texto;
    }

    // ── Validación ─────────────────────────────────────────────

    /**
     * Normaliza y valida lo que llega del formulario.
     *
     * @return array{errores: array<string, string>, aviso: array}
     *   `errores` por campo (titulo, contenido, cartas, posicion), para
     *   pintarlos junto a su control; `aviso` es lo que se guarda.
     */
    public static function validar(array $entrada): array
    {
        $errores = [];

        $titulo = is_scalar($entrada['titulo'] ?? null) ? (string) $entrada['titulo'] : '';
        // Primero los blancos a un espacio (un tabulador separa palabras) y
        // después fuera el resto de caracteres de control.
        $titulo = trim(self::sinControl((string) preg_replace('/\s+/u', ' ', $titulo)));
        if ($titulo === '') {
            $errores['titulo'] = 'Escribe un título: es lo primero que se lee del aviso.';
        } elseif (mb_strlen($titulo) > self::MAX_TITULO) {
            $errores['titulo'] = 'El título admite hasta ' . self::MAX_TITULO . ' caracteres.';
        }

        $contenido = is_scalar($entrada['contenido'] ?? null) ? (string) $entrada['contenido'] : '';
        $contenido = self::normalizarContenido($contenido);
        if ($contenido === '' || self::bloques($contenido) === []) {
            $errores['contenido'] = 'Escribe el contenido del aviso.';
        } elseif (mb_strlen($contenido) > self::MAX_CONTENIDO) {
            $errores['contenido'] = 'El contenido admite hasta ' . self::MAX_CONTENIDO . ' caracteres; resume o divídelo en dos avisos.';
        } elseif (substr_count($contenido, "\n") + 1 > self::MAX_RENGLONES) {
            $errores['contenido'] = 'El contenido admite hasta ' . self::MAX_RENGLONES . ' renglones.';
        } elseif (preg_match('/<\/?[a-z][a-z0-9-]*(\s[^>]*)?\/?>/i', $contenido)) {
            // Se imprimiría la etiqueta tal cual: mejor decirlo ahora.
            $errores['contenido'] = 'Escribe sin etiquetas HTML: usa ## al inicio de un renglón para un subtítulo y **texto** para negritas.';
        }

        $cartasEntrada = $entrada['cartas'] ?? [];
        $cartasEntrada = is_array($cartasEntrada) ? $cartasEntrada : [$cartasEntrada];
        $cartas = [];
        foreach (array_keys(self::CARTAS) as $clave) {
            $cartas[$clave] = in_array($clave, array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $cartasEntrada), true);
        }
        if (!in_array(true, $cartas, true)) {
            $errores['cartas'] = 'Elige al menos una carta: Menú, Maridaje o las dos.';
        }

        $posicion = is_scalar($entrada['posicion'] ?? null) ? (string) $entrada['posicion'] : '';
        if (!isset(self::POSICIONES[$posicion])) {
            $errores['posicion'] = 'Elige si el aviso va al inicio o al final de la carta.';
            $posicion = 'inicio';
        }

        $activo = isset($entrada['activo']) && is_scalar($entrada['activo']) && (string) $entrada['activo'] === '1';

        return [
            'errores' => $errores,
            'aviso' => [
                'titulo' => mb_substr($titulo, 0, self::MAX_TITULO),
                'contenido' => $contenido,
                'cartas' => $cartas,
                'posicion' => $posicion,
                'activo' => $activo,
            ],
        ];
    }

    /** Un aviso en blanco para el editor, o el de una plantilla. */
    public static function nuevo(string $plantilla = ''): array
    {
        $base = [
            'id' => null,
            'titulo' => '',
            'contenido' => '',
            'cartas' => ['comida' => true, 'maridaje' => false],
            'posicion' => 'inicio',
            'activo' => true,
        ];
        if (!isset(self::PLANTILLAS[$plantilla])) {
            return $base;
        }
        $p = self::PLANTILLAS[$plantilla];
        return array_merge($base, [
            'titulo' => $p['titulo'],
            'contenido' => $p['contenido'],
            'posicion' => $p['posicion'],
            'cartas' => array_combine(array_keys(self::CARTAS), array_map(static fn (string $c): bool => in_array($c, $p['cartas'], true), array_keys(self::CARTAS))),
        ]);
    }

    // ── Lectura ────────────────────────────────────────────────

    /**
     * Todos los avisos de Restaurante para el panel, en el orden de la carta
     * (los del inicio primero). Lanza si la tabla no existe: el panel lo
     * explica en vez de enseñar una lista vacía engañosa.
     *
     * @return list<array>
     */
    public static function listar(): array
    {
        ContextoNegocio::exigir('configuration', 'ver', 'restaurante');
        $stmt = self::db()->prepare(
            "SELECT id, titulo, contenido, activo, en_comida, en_maridaje, posicion, orden
               FROM configuracion_avisos
              WHERE unidad_id = ?
              ORDER BY FIELD(posicion, 'inicio', 'final'), orden, id"
        );
        $unidad = ContextoNegocio::unidad('restaurante');
        $stmt->bind_param('i', $unidad);
        $stmt->execute();
        $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return array_map([self::class, 'desdeFila'], $filas);
    }

    /**
     * Los avisos activos de una carta, ya en HTML con las clases del PDF:
     * ['inicio' => [['titulo', 'html']], 'final' => [...]].
     *
     * Sin permiso a propósito: la carta pública (/menu/pdf) los imprime. Nunca
     * lanza; cualquier fallo es una carta sin avisos.
     */
    public static function paraPdf(string $carta): array
    {
        $vacio = ['inicio' => [], 'final' => []];
        if (!isset(self::CARTAS[$carta])) {
            return $vacio;
        }
        try {
            $db = ActiveRecord::getDB();
            if (!$db instanceof \mysqli) {
                return $vacio;
            }
            $columna = self::CARTAS[$carta]['columna'];
            $stmt = $db->prepare(
                "SELECT a.titulo, a.contenido, a.posicion
                   FROM configuracion_avisos a
                   JOIN unidades u ON u.id = a.unidad_id AND u.slug = 'restaurante'
                  WHERE a.activo = 1 AND a.{$columna} = 1
                  ORDER BY a.orden, a.id
                  LIMIT " . self::MAX_AVISOS
            );
            $stmt->execute();
            $filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('AvisosCarta::paraPdf - ' . $e->getMessage());
            return $vacio;
        }

        $salida = $vacio;
        foreach ($filas as $fila) {
            $html = self::html((string) $fila['contenido'], ['h2' => 'aviso__h2', 'p' => 'aviso__p', 'b' => 'aviso__b']);
            if ($html === '') {
                continue;
            }
            $posicion = isset($salida[$fila['posicion']]) ? (string) $fila['posicion'] : 'inicio';
            $salida[$posicion][] = ['titulo' => (string) $fila['titulo'], 'html' => $html];
        }
        return $salida;
    }

    // ── Escritura ──────────────────────────────────────────────

    /**
     * Crea (id null) o actualiza un aviso ya validado por validar().
     *
     * @return array{ok: bool, id?: int, codigo?: string, mensaje?: string}
     */
    public static function guardar(?int $id, array $aviso): array
    {
        return self::escribir(static function (\mysqli $db, int $unidad, ?int $actor) use ($id, $aviso): array {
            $comida = !empty($aviso['cartas']['comida']) ? 1 : 0;
            $maridaje = !empty($aviso['cartas']['maridaje']) ? 1 : 0;
            $activo = !empty($aviso['activo']) ? 1 : 0;
            $posicion = (string) $aviso['posicion'];
            $titulo = (string) $aviso['titulo'];
            $contenido = (string) $aviso['contenido'];

            if ($id === null) {
                $total = (int) self::uno($db, 'SELECT COUNT(*) FROM configuracion_avisos WHERE unidad_id = ? FOR UPDATE', 'i', [$unidad]);
                if ($total >= self::MAX_AVISOS) {
                    throw new InventarioError('AVISOS_LIMITE', 'La carta admite hasta ' . self::MAX_AVISOS . ' avisos. Elimina o reutiliza uno de los que ya tienes.');
                }
                $orden = self::siguienteOrden($db, $unidad, $posicion);
                $stmt = $db->prepare('INSERT INTO configuracion_avisos (unidad_id, titulo, contenido, activo, en_comida, en_maridaje, posicion, orden, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issiiisii', $unidad, $titulo, $contenido, $activo, $comida, $maridaje, $posicion, $orden, $actor);
                $stmt->execute();
                $nuevo = (int) $db->insert_id;
                $stmt->close();
                return ['id' => $nuevo];
            }

            $actual = self::bloquear($db, $unidad, $id);
            // Al cambiar de lugar entra al final de su grupo nuevo, no a la
            // altura que tenía en el otro.
            $orden = $actual['posicion'] === $posicion ? (int) $actual['orden'] : self::siguienteOrden($db, $unidad, $posicion);
            $stmt = $db->prepare('UPDATE configuracion_avisos SET titulo = ?, contenido = ?, activo = ?, en_comida = ?, en_maridaje = ?, posicion = ?, orden = ?, updated_by = ? WHERE id = ? AND unidad_id = ?');
            $stmt->bind_param('ssiiisiiii', $titulo, $contenido, $activo, $comida, $maridaje, $posicion, $orden, $actor, $id, $unidad);
            $stmt->execute();
            $stmt->close();
            return ['id' => $id];
        });
    }

    public static function cambiarEstado(int $id, bool $activo): array
    {
        return self::escribir(static function (\mysqli $db, int $unidad, ?int $actor) use ($id, $activo): array {
            self::bloquear($db, $unidad, $id);
            $valor = $activo ? 1 : 0;
            $stmt = $db->prepare('UPDATE configuracion_avisos SET activo = ?, updated_by = ? WHERE id = ? AND unidad_id = ?');
            $stmt->bind_param('iiii', $valor, $actor, $id, $unidad);
            $stmt->execute();
            $stmt->close();
            return ['id' => $id];
        });
    }

    /** Sube o baja un aviso dentro de su lugar (inicio o final) y renumera el grupo. */
    public static function mover(int $id, string $direccion): array
    {
        return self::escribir(static function (\mysqli $db, int $unidad, ?int $actor) use ($id, $direccion): array {
            if (!in_array($direccion, ['subir', 'bajar'], true)) {
                throw new InventarioError('DIRECCION_INVALIDA', 'Movimiento no válido.');
            }
            $actual = self::bloquear($db, $unidad, $id);
            $stmt = $db->prepare('SELECT id FROM configuracion_avisos WHERE unidad_id = ? AND posicion = ? ORDER BY orden, id FOR UPDATE');
            $posicion = (string) $actual['posicion'];
            $stmt->bind_param('is', $unidad, $posicion);
            $stmt->execute();
            $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
            $stmt->close();

            $i = array_search($id, $ids, true);
            $j = $direccion === 'subir' ? $i - 1 : $i + 1;
            if ($i === false || $j < 0 || $j >= count($ids)) {
                return ['id' => $id, 'sin_cambio' => true];
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            $upd = $db->prepare('UPDATE configuracion_avisos SET orden = ?, updated_by = ? WHERE id = ? AND unidad_id = ?');
            foreach ($ids as $orden => $avisoId) {
                $upd->bind_param('iiii', $orden, $actor, $avisoId, $unidad);
                $upd->execute();
            }
            $upd->close();
            return ['id' => $id];
        });
    }

    public static function eliminar(int $id): array
    {
        return self::escribir(static function (\mysqli $db, int $unidad) use ($id): array {
            self::bloquear($db, $unidad, $id);
            $stmt = $db->prepare('DELETE FROM configuracion_avisos WHERE id = ? AND unidad_id = ?');
            $stmt->bind_param('ii', $id, $unidad);
            $stmt->execute();
            $stmt->close();
            return ['id' => $id];
        });
    }

    // ── Internos ───────────────────────────────────────────────

    /**
     * Toda escritura: transacción, permiso de editar Configuración de
     * Restaurante releído dentro de ella y el actor de la sesión.
     */
    private static function escribir(callable $accion): array
    {
        $resultado = ContextoNegocio::transaccion(static function () use ($accion): array {
            ContextoNegocio::exigir('configuration', 'editar', 'restaurante', true);
            $actor = ContextoNegocio::actorId();
            return $accion(self::db(), ContextoNegocio::unidad('restaurante'), $actor > 0 ? $actor : null);
        });
        if (($resultado['ok'] ?? null) === false) {
            return ['ok' => false, 'codigo' => (string) ($resultado['codigo'] ?? 'ERROR'), 'mensaje' => (string) ($resultado['mensaje'] ?? '')];
        }
        return ['ok' => true] + $resultado;
    }

    private static function bloquear(\mysqli $db, int $unidad, int $id): array
    {
        $stmt = $db->prepare('SELECT id, posicion, orden FROM configuracion_avisos WHERE id = ? AND unidad_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $id, $unidad);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fila) {
            throw new InventarioError('AVISO_NO_ENCONTRADO', 'Ese aviso ya no existe; puede que alguien lo haya eliminado.');
        }
        return $fila;
    }

    private static function siguienteOrden(\mysqli $db, int $unidad, string $posicion): int
    {
        return (int) self::uno($db, 'SELECT COALESCE(MAX(orden) + 1, 0) FROM configuracion_avisos WHERE unidad_id = ? AND posicion = ?', 'is', [$unidad, $posicion]);
    }

    private static function uno(\mysqli $db, string $sql, string $tipos, array $valores): mixed
    {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($tipos, ...$valores);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_row();
        $stmt->close();
        return $fila[0] ?? null;
    }

    private static function desdeFila(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'titulo' => (string) $fila['titulo'],
            'contenido' => (string) $fila['contenido'],
            'activo' => (int) $fila['activo'] === 1,
            'cartas' => ['comida' => (int) $fila['en_comida'] === 1, 'maridaje' => (int) $fila['en_maridaje'] === 1],
            'posicion' => isset(self::POSICIONES[$fila['posicion']]) ? (string) $fila['posicion'] : 'inicio',
            'orden' => (int) $fila['orden'],
        ];
    }

    /**
     * Saltos uniformes, tabuladores como espacio, sin espacios al final de
     * cada renglón y como mucho un renglón en blanco seguido.
     */
    private static function normalizarContenido(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $texto);
        $texto = self::sinControl($texto, true);
        $texto = implode("\n", array_map('rtrim', explode("\n", $texto)));
        $texto = (string) preg_replace("/\n{3,}/", "\n\n", $texto);
        return trim($texto, "\n ");
    }

    private static function sinControl(string $texto, bool $conSaltos = false): string
    {
        return (string) preg_replace($conSaltos ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $texto);
    }

    /**
     * Un renglón partido en tramos normales y en negritas. Con un número impar
     * de `**` el último no tiene pareja y se queda como texto.
     */
    private static function segmentos(string $linea): array
    {
        $partes = explode('**', $linea);
        if (count($partes) % 2 === 0) {
            $ultimo = array_pop($partes);
            $partes[count($partes) - 1] .= '**' . $ultimo;
        }
        $segmentos = [];
        foreach ($partes as $i => $parte) {
            if ($parte === '') {
                continue;
            }
            $segmentos[] = ['texto' => $parte, 'negrita' => $i % 2 === 1 && trim($parte) !== ''];
        }
        return $segmentos;
    }

    private static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function db(): \mysqli
    {
        return ActiveRecord::getDB();
    }
}
