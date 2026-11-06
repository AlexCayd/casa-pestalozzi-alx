<?php

namespace Services\Users;

use Services\Security\PermisosUsuario;

/**
 * Perfiles sugeridos para agilizar el alta en Usuarios.
 *
 * Un perfil solo rellena el formulario en el navegador. Lo que se guarda siguen
 * siendo permisos directos, validados por AccesosUsuario contra el techo del actor;
 * no es un rol, no se persiste y no concede derechos implícitos. Cambiar una
 * definición no altera a los usuarios ya guardados.
 */
final class PerfilesSugeridos
{
    /** Todos los módulos del ámbito al nivel máximo y todas sus facultades delegables. */
    public const TODO = '*';

    public const PERFILES = [
        'mesero' => [
            'titulo' => 'Mesero',
            'detalle' => 'Solo punto de venta de Restaurante. Entra con NIP.',
            'ambitos' => ['restaurante' => ['permisos' => ['pdv' => 'operar']]],
        ],
        // El piso de Panadería es su caja de mostrador (sin mesas ni cocina).
        'cajero_panaderia' => [
            'titulo' => 'Cajero de Panadería',
            'detalle' => 'Caja de mostrador y horneadas. Entra con NIP; para anular pide el NIP de un encargado.',
            'ambitos' => ['panaderia' => ['permisos' => ['pdv' => 'operar', 'vitrina' => 'operar']]],
        ],
        'encargado_caja_panaderia' => [
            'titulo' => 'Encargado de caja de Panadería',
            'detalle' => 'Caja, horneadas y anulación de cobros del día. Entra con NIP.',
            'ambitos' => ['panaderia' => ['permisos' => ['pdv' => 'operar', 'vitrina' => 'operar', 'anular' => 'operar']]],
        ],
        'admin_restaurante' => [
            'titulo' => 'Administrador de Restaurante',
            'detalle' => 'Todo Restaurante: panel, POS, producción, usuarios y transferencias.',
            'ambitos' => ['restaurante' => self::TODO],
        ],
        'admin_panaderia' => [
            'titulo' => 'Administrador de Panadería',
            'detalle' => 'Todo Panadería: panel, caja, usuarios y transferencias.',
            'ambitos' => ['panaderia' => self::TODO],
        ],
        // No asigna es_superadmin: la cuenta protegida queda fuera del CRUD ordinario.
        'acceso_total' => [
            'titulo' => 'Acceso total',
            'detalle' => 'Todo Global, Restaurante y Panadería. No es la cuenta protegida de superadministrador.',
            'ambitos' => ['global' => self::TODO, 'restaurante' => self::TODO, 'panaderia' => self::TODO],
        ],
    ];

    /** Perfiles cuyos ámbitos completos puede gestionar el actor en este formulario. */
    public static function disponibles(array $ambitosPermitidos): array
    {
        $disponibles = [];
        foreach (self::PERFILES as $clave => $perfil) {
            if (array_diff(array_keys($perfil['ambitos']), $ambitosPermitidos) === []) {
                $disponibles[$clave] = $perfil;
            }
        }
        return $disponibles;
    }

    /** Lista los módulos o niveles definidos que no existen en el catálogo vigente. */
    public static function inconsistencias(): array
    {
        $errores = [];
        foreach (self::PERFILES as $clave => $perfil) {
            foreach ($perfil['ambitos'] as $slug => $regla) {
                $catalogo = PermisosUsuario::catalogo($slug);
                if ($catalogo === []) {
                    $errores[] = "$clave: ámbito desconocido $slug";
                    continue;
                }
                if ($regla === self::TODO) continue;
                foreach ($regla['permisos'] ?? [] as $modulo => $nivel) {
                    if (!in_array($nivel, $catalogo[$modulo]['niveles'] ?? [], true)) {
                        $errores[] = "$clave: $slug/$modulo/$nivel no existe";
                    }
                }
            }
        }
        return $errores;
    }
}
