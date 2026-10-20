<?php
/**
 * Roles y permisos — listado.
 *
 * Una fila por rol: tipo, cómo entra (deducido de sus permisos), qué módulos
 * abre y cuántas cuentas lo tienen. Los de sistema se ven pero no se tocan;
 * un rol con más permisos que el de quien mira, tampoco.
 */
use Services\Security\Permisos;

$filas = is_array($filas ?? null) ? $filas : [];
$puedeEditar = !empty($puedeEditar);
$h = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');

$tipos = [
    'sistema' => ['etiqueta' => 'Sistema', 'clase' => 'admin-badge--neutral'],
    'predefinido' => ['etiqueta' => 'Predefinido', 'clase' => 'admin-badge--info'],
    'personalizado' => ['etiqueta' => 'Personalizado', 'clase' => 'admin-badge--neutral admin-badge--outline'],
];
$accesos = [
    Permisos::ACCESO_CONTRASENA => 'Usuario y contraseña',
    Permisos::ACCESO_NIP => 'NIP (tablet)',
    Permisos::ACCESO_NINGUNO => 'Sin acceso',
];
$totalModulos = count(Permisos::MODULOS);
?>
<section class="admin-menu admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Seguridad</span>
            <h2 class="admin-page__title">Roles y permisos</h2>
            <p class="admin-page__subtitle">Qué partes del sistema abre cada rol. Cada usuario tiene un rol; se asigna en Usuarios.</p>
        </div>
        <?php if ($puedeEditar) : ?>
            <div class="admin-menu__actions admin-actions">
                <a class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary" href="/admin/roles/crear">Nuevo rol</a>
            </div>
        <?php endif; ?>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <section class="admin-menu__panel admin-panel admin-card">
        <div class="admin-menu__panel-head">
            <div>
                <h3>Roles</h3>
                <p>Los dos de sistema lo tienen todo y no se modifican. Mesero y Cocinero se editan, pero no se borran.</p>
            </div>
        </div>

        <div class="admin-table-wrap" data-scrollable>
            <table class="admin-table admin-menu__table">
                <thead>
                    <tr>
                        <th scope="col">Rol</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Entra con</th>
                        <th scope="col">Módulos</th>
                        <th scope="col" class="admin-num">Usuarios</th>
                        <th scope="col"><span class="admin-sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $fila) : ?>
                        <?php
                        $rol = $fila['rol'];
                        $permisos = $fila['permisos'];
                        $tipo = $tipos[(string) $rol->tipo] ?? $tipos['personalizado'];
                        $soloConsulta = count(array_filter($permisos, static fn ($n) => $n === Permisos::VER));
                        if ((string) $rol->tipo === 'sistema') {
                            $resumen = 'Todos, con edición';
                        } else {
                            $resumen = count($permisos) . ' de ' . $totalModulos;
                            if ($soloConsulta > 0) {
                                $resumen .= ' · ' . $soloConsulta . ' sólo consulta';
                            }
                        }
                        $titulos = array_map(
                            static fn ($clave) => Permisos::MODULOS[$clave]['titulo'] . ($permisos[$clave] === Permisos::VER ? ' (consulta)' : ''),
                            array_keys($permisos)
                        );
                        ?>
                        <tr>
                            <td>
                                <span class="admin-table__cell-main"><?php echo $h($rol->nombre); ?></span>
                                <?php if ((string) $rol->descripcion !== '') : ?>
                                    <span class="admin-table__cell-sub"><?php echo $h($rol->descripcion); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="admin-badge <?php echo $tipo['clase']; ?>"><?php echo $h($tipo['etiqueta']); ?></span></td>
                            <td><?php echo $h($accesos[$fila['acceso']] ?? ''); ?></td>
                            <td>
                                <span class="admin-table__cell-main"><?php echo $h($resumen); ?></span>
                                <?php if ((string) $rol->tipo !== 'sistema' && $titulos !== []) : ?>
                                    <span class="admin-table__cell-sub"><?php echo $h(implode(', ', $titulos)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="admin-num"><?php echo (int) $fila['usuarios']; ?></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a class="admin-icon-button admin-icon-button--edit"
                                       href="/admin/roles/editar?id=<?php echo (int) $rol->id; ?>"
                                       title="<?php echo $fila['gestionable'] ? 'Editar' : 'Ver permisos'; ?>"
                                       aria-label="<?php echo $fila['gestionable'] ? 'Editar' : 'Ver permisos de'; ?> el rol <?php echo $h($rol->nombre); ?>">
                                        <?php if ($fila['gestionable']) : ?>
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        <?php else : ?>
                                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php endif; ?>
                                    </a>
                                    <?php if ($fila['gestionable'] && (string) $rol->tipo === 'personalizado') : ?>
                                        <?php /* Con usuarios no se borra: el servidor lo rechaza
                                                 igual, pero el botón ya dice por qué. */ ?>
                                        <form method="POST" action="/admin/roles/eliminar"
                                              data-confirm-delete
                                              data-confirm-eyebrow="Eliminar rol"
                                              data-confirm-title="¿Eliminar el rol «<?php echo $h($rol->nombre); ?>»?"
                                              data-confirm-require="<?php echo $h($rol->nombre); ?>"
                                              data-confirm-description="Sus permisos se borran con él."
                                              data-confirm-consequence="Esta acción no se puede deshacer.">
                                            <input type="hidden" name="id" value="<?php echo (int) $rol->id; ?>">
                                            <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken ?? ''); ?>">
                                            <button type="submit"
                                                    class="admin-icon-button admin-icon-button--danger"
                                                    title="<?php echo (int) $fila['usuarios'] > 0 ? 'Tiene usuarios: reasígnalos en Usuarios antes de borrarlo' : 'Eliminar'; ?>"
                                                    aria-label="Eliminar el rol <?php echo $h($rol->nombre); ?>"
                                                    <?php echo (int) $fila['usuarios'] > 0 ? 'disabled' : ''; ?>>
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
