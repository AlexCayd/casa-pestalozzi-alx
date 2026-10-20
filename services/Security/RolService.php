<?php

namespace Services\Security;

use Model\ActiveRecord;
use Model\Rol;

/**
 * Altas, cambios y bajas de roles, y quién puede tocar qué.
 *
 * El «actor» es el rol de la sesión tal como lo entrega Classes\Auth::rolActual():
 * [slug, nombre, sistema, permisos]. Las reglas:
 *
 *   - Los roles de SISTEMA (super admin y administrador) no los edita ni los
 *     borra nadie: sus permisos son «todo» y se calculan.
 *   - Nadie concede más de lo que tiene (Permisos::puedeOtorgar): un rol con
 *     «Roles y permisos» en editar sólo crea o edita roles que caben en los
 *     suyos. Los de sistema lo tienen todo, así que no tienen ese tope.
 *   - Un rol con usuarios no cambia de vía de acceso (contraseña ↔ NIP): sus
 *     cuentas tienen la credencial de la otra vía y se quedarían sin poder
 *     entrar. Primero se reasignan o se crea un rol nuevo.
 *   - Un rol con usuarios no se borra (primero se reasignan), y los
 *     predefinidos —Mesero, Cocinero— no se borran nunca.
 *
 * Y para Usuarios: el super admin asigna cualquier rol y edita cualquier
 * cuenta; el resto sólo asigna —y sólo edita cuentas de— roles que no sean de
 * sistema y que quepan en sus propios permisos.
 */
final class RolService
{
    public const GUARDADO = 'ROL_GUARDADO';
    public const ELIMINADO = 'ROL_ELIMINADO';
    public const NO_EXISTE = 'ROL_NO_EXISTE';
    public const PROTEGIDO = 'ROL_PROTEGIDO';
    public const EXCEDE_PERMISOS = 'ROL_EXCEDE_PERMISOS';
    public const CAMBIO_ACCESO = 'ROL_CAMBIO_ACCESO_CON_USUARIOS';
    public const CON_USUARIOS = 'ROL_CON_USUARIOS';
    public const DATOS_INVALIDOS = 'ROL_DATOS_INVALIDOS';
    public const ERROR = 'ROL_ERROR';

    // ── Quién puede qué ───────────────────────────────────────────

    /** ¿Puede el actor editar (o borrar) este rol? */
    public static function puedeGestionar(array $actor, string $tipo, array $permisosRol): bool
    {
        if ($tipo === Rol::TIPO_SISTEMA) {
            return false;
        }

        return Permisos::puedeOtorgar(!empty($actor['sistema']), $actor['permisos'] ?? [], $permisosRol);
    }

    /**
     * Roles que el actor puede asignar a una cuenta.
     *
     * @param array<string, array{rol: Rol, permisos: array<string, string>, acceso: string}> $catalogo
     * @return list<string> slugs
     */
    public static function rolesAsignables(array $actor, array $catalogo): array
    {
        $asignables = [];
        foreach ($catalogo as $slug => $info) {
            if (self::puedeAsignar($actor, $slug, $info['permisos'])) {
                $asignables[] = $slug;
            }
        }

        return $asignables;
    }

    public static function puedeAsignar(array $actor, string $slug, array $permisosRol): bool
    {
        if (($actor['slug'] ?? '') === Permisos::SUPER_ADMIN) {
            return true;
        }
        if (Permisos::esSistema($slug)) {
            return false;
        }

        return Permisos::puedeOtorgar(!empty($actor['sistema']), $actor['permisos'] ?? [], $permisosRol);
    }

    // ── Escrituras ────────────────────────────────────────────────

    /**
     * Crea ($id = 0) o edita un rol.
     *
     * @param array{nombre?: string, descripcion?: string, permisos?: array} $datos
     *        permisos: [modulo => 'ver'|'editar'|''] — vacío = sin acceso
     * @return array{ok: bool, codigo: string, errores?: list<string>, id?: int}
     */
    public static function guardar(int $id, array $datos, array $actor): array
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        $descripcion = trim((string) ($datos['descripcion'] ?? ''));
        $permisos = Permisos::normalizar(is_array($datos['permisos'] ?? null) ? $datos['permisos'] : []);

        $errores = [];
        if ($nombre === '') {
            $errores[] = 'El nombre del rol es obligatorio.';
        } elseif (mb_strlen($nombre) > 60) {
            $errores[] = 'El nombre del rol admite hasta 60 caracteres.';
        }
        if (mb_strlen($descripcion) > 255) {
            $errores[] = 'La descripción admite hasta 255 caracteres.';
        }
        if ($permisos === []) {
            $errores[] = 'Da acceso al menos a un módulo: un rol sin módulos no puede entrar al sistema.';
        }
        if ($errores !== []) {
            return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'errores' => $errores];
        }

        if (!Permisos::puedeOtorgar(!empty($actor['sistema']), $actor['permisos'] ?? [], $permisos)) {
            return ['ok' => false, 'codigo' => self::EXCEDE_PERMISOS, 'errores' => [
                'No puedes dar un permiso que tu propio rol no tiene.',
            ]];
        }

        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();

            $existente = null;
            if ($id > 0) {
                $existente = self::filaRol($id, true);
                if (!$existente) {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => self::NO_EXISTE];
                }
                $actuales = self::permisosDe($id);
                if (!self::puedeGestionar($actor, (string) $existente['tipo'], $actuales)) {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => self::PROTEGIDO, 'errores' => [
                        $existente['tipo'] === Rol::TIPO_SISTEMA
                            ? 'Los roles de sistema no se editan.'
                            : 'Este rol tiene permisos que el tuyo no tiene: no puedes editarlo.',
                    ]];
                }

                $usuarios = self::usuariosDe((string) $existente['slug']);
                $accesoAntes = Permisos::acceso(false, $actuales);
                $accesoDespues = Permisos::acceso(false, $permisos);
                if ($usuarios > 0 && $accesoAntes !== $accesoDespues) {
                    $db->rollback();
                    return ['ok' => false, 'codigo' => self::CAMBIO_ACCESO, 'errores' => [self::mensajeCambioAcceso($usuarios, $accesoAntes)]];
                }

                $stmt = $db->prepare('UPDATE roles SET nombre = ?, descripcion = ? WHERE id = ? LIMIT 1');
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }
                $desc = $descripcion !== '' ? $descripcion : null;
                $stmt->bind_param('ssi', $nombre, $desc, $id);
                self::ejecutar($stmt);
            } else {
                $slug = self::slugNuevo($nombre);
                $stmt = $db->prepare(
                    "INSERT INTO roles (slug, nombre, descripcion, tipo) VALUES (?, ?, ?, 'personalizado')"
                );
                if (!$stmt) {
                    throw new \RuntimeException($db->error);
                }
                $desc = $descripcion !== '' ? $descripcion : null;
                $stmt->bind_param('sss', $slug, $nombre, $desc);
                self::ejecutar($stmt);
                $id = (int) $db->insert_id;
            }

            $borrar = $db->prepare('DELETE FROM rol_permisos WHERE rol_id = ?');
            if (!$borrar) {
                throw new \RuntimeException($db->error);
            }
            $borrar->bind_param('i', $id);
            self::ejecutar($borrar);

            $insertar = $db->prepare('INSERT INTO rol_permisos (rol_id, modulo, nivel) VALUES (?, ?, ?)');
            if (!$insertar) {
                throw new \RuntimeException($db->error);
            }
            foreach ($permisos as $modulo => $nivel) {
                $insertar->bind_param('iss', $id, $modulo, $nivel);
                if (!$insertar->execute()) {
                    throw new \RuntimeException($insertar->error);
                }
            }
            $insertar->close();

            $db->commit();
            return ['ok' => true, 'codigo' => self::GUARDADO, 'id' => $id];
        } catch (\Throwable $e) {
            self::rollback($db);
            if ((int) $e->getCode() === 1062 || str_contains($e->getMessage(), 'Duplicate')) {
                return ['ok' => false, 'codigo' => self::DATOS_INVALIDOS, 'errores' => ['Ya existe un rol con ese nombre.']];
            }
            error_log('RolService::guardar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR];
        }
    }

    /** @return array{ok: bool, codigo: string, errores?: list<string>} */
    public static function eliminar(int $id, array $actor): array
    {
        $db = ActiveRecord::getDB();
        try {
            $db->begin_transaction();
            $fila = self::filaRol($id, true);
            if (!$fila) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::NO_EXISTE];
            }
            if ($fila['tipo'] !== Rol::TIPO_PERSONALIZADO) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::PROTEGIDO, 'errores' => [
                    $fila['tipo'] === Rol::TIPO_SISTEMA
                        ? 'Los roles de sistema no se borran.'
                        : 'Los roles predefinidos no se borran; puedes editar sus permisos.',
                ]];
            }
            if (!self::puedeGestionar($actor, (string) $fila['tipo'], self::permisosDe($id))) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::PROTEGIDO, 'errores' => [
                    'Este rol tiene permisos que el tuyo no tiene: no puedes borrarlo.',
                ]];
            }
            $usuarios = self::usuariosDe((string) $fila['slug']);
            if ($usuarios > 0) {
                $db->rollback();
                return ['ok' => false, 'codigo' => self::CON_USUARIOS, 'errores' => [
                    sprintf(
                        '%s tiene el rol «%s». Asígnales otro rol desde Usuarios antes de borrarlo.',
                        $usuarios === 1 ? 'Un usuario' : $usuarios . ' usuarios',
                        $fila['nombre']
                    ),
                ]];
            }

            // rol_permisos se va con ON DELETE CASCADE.
            $stmt = $db->prepare('DELETE FROM roles WHERE id = ? LIMIT 1');
            if (!$stmt) {
                throw new \RuntimeException($db->error);
            }
            $stmt->bind_param('i', $id);
            self::ejecutar($stmt);
            $db->commit();

            return ['ok' => true, 'codigo' => self::ELIMINADO];
        } catch (\Throwable $e) {
            self::rollback($db);
            error_log('RolService::eliminar - ' . $e->getMessage());
            return ['ok' => false, 'codigo' => self::ERROR];
        }
    }

    // ── Internos ──────────────────────────────────────────────────

    public static function mensajeCambioAcceso(int $usuarios, string $accesoAntes): string
    {
        $uno = $usuarios === 1;
        $quien = $uno ? 'Un usuario tiene este rol y entra' : $usuarios . ' usuarios tienen este rol y entran';
        $cuenta = $uno ? 'su cuenta no tiene' : 'sus cuentas no tienen';
        $a = $uno ? 'asígnale' : 'asígnales';
        if ($accesoAntes === Permisos::ACCESO_NIP) {
            return $quien . ' con NIP. Con módulos del panel entraría' . ($uno ? '' : 'n') . ' con contraseña, que ' . $cuenta . '. '
                . 'Crea un rol nuevo, o ' . $a . ' otro rol desde Usuarios antes de cambiar éste.';
        }

        return $quien . ' con contraseña. Sin módulos del panel entraría' . ($uno ? '' : 'n') . ' con NIP, que ' . $cuenta . '. '
            . 'Crea un rol nuevo, o ' . $a . ' otro rol desde Usuarios antes de cambiar éste.';
    }

    /**
     * Slug a partir del nombre: minúsculas ASCII, guiones bajos y un sufijo si
     * ya existe. Nunca cambia después: es lo que guarda usuarios.rol.
     */
    private static function slugNuevo(string $nombre): string
    {
        $base = strtolower((string) preg_replace(
            '/[^a-zA-Z0-9]+/',
            '_',
            (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre)
        ));
        $base = trim($base, '_');
        $base = substr($base !== '' ? $base : 'rol', 0, 30);

        $slug = $base;
        $n = 2;
        while (Rol::porSlug($slug) !== null || Permisos::esSistema($slug)) {
            $slug = $base . '_' . $n++;
        }

        return $slug;
    }

    private static function filaRol(int $id, bool $paraActualizar = false): ?array
    {
        $stmt = ActiveRecord::getDB()->prepare(
            'SELECT id, slug, nombre, tipo FROM roles WHERE id = ? LIMIT 1' . ($paraActualizar ? ' FOR UPDATE' : '')
        );
        if (!$stmt) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $stmt->bind_param('i', $id);
        self::ejecutar($stmt, false);
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $fila;
    }

    private static function permisosDe(int $id): array
    {
        // Sin pasar por el tipo: aquí interesan los renglones guardados, y a un
        // rol de sistema ya lo frenó puedeGestionar() antes.
        $rol = new Rol();
        $rol->id = $id;
        $rol->tipo = Rol::TIPO_PERSONALIZADO;

        return $rol->permisos();
    }

    private static function usuariosDe(string $slug): int
    {
        $stmt = ActiveRecord::getDB()->prepare('SELECT COUNT(*) AS n FROM usuarios WHERE rol = ?');
        if (!$stmt) {
            throw new \RuntimeException(ActiveRecord::getDB()->error);
        }
        $stmt->bind_param('s', $slug);
        self::ejecutar($stmt, false);
        $n = (int) ($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        return $n;
    }

    private static function ejecutar(\mysqli_stmt $stmt, bool $cerrar = true): void
    {
        if (!$stmt->execute()) {
            throw new \RuntimeException($stmt->error, (int) $stmt->errno);
        }
        if ($cerrar) {
            $stmt->close();
        }
    }

    private static function rollback(\mysqli $db): void
    {
        try {
            $db->rollback();
        } catch (\Throwable $e) {
            error_log('RolService rollback - ' . $e->getMessage());
        }
    }
}
