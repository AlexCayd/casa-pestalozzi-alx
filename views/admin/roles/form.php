<?php
/**
 * Roles y permisos — alta y edición de un rol.
 *
 * La matriz es una fila por módulo con tres opciones excluyentes: sin
 * acceso, consulta o edición. Son admin-pills (radios reales), así que el
 * formulario funciona sin JS y conserva lo elegido tras un POST fallido.
 *
 * Las opciones por encima de lo que tiene quien edita salen deshabilitadas:
 * nadie da un permiso que no tiene (RolService lo vuelve a comprobar).
 */
use Services\Security\Permisos;

$datos = is_array($datos ?? null) ? $datos : ['nombre' => '', 'descripcion' => '', 'permisos' => []];
$permisos = is_array($datos['permisos'] ?? null) ? $datos['permisos'] : [];
$topeActor = is_array($topeActor ?? null) ? $topeActor : [];
$soloLectura = !empty($soloLectura);
$tipo = (string) ($tipo ?? 'personalizado');
$usuarios = (int) ($usuarios ?? 0);
$esSistema = $tipo === 'sistema';
$h = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');

$grupos = [
    Permisos::GRUPO_PANEL => [
        'titulo' => 'Panel de administración',
        'detalle' => 'Con cualquiera de estos módulos, el rol entra con usuario y contraseña.',
    ],
    Permisos::GRUPO_PISO => [
        'titulo' => 'Pantallas de piso',
        'detalle' => 'Si el rol sólo tiene éstas, entra con NIP desde la tablet.',
    ],
];
$opciones = [
    '' => ['titulo' => 'Sin acceso', 'hint' => 'No aparece en su menú'],
    Permisos::VER => ['titulo' => 'Consulta', 'hint' => 'Ve, no cambia nada'],
    Permisos::EDITAR => ['titulo' => 'Edición', 'hint' => 'Ve y hace cambios'],
];
$acceso = Permisos::acceso($esSistema, $permisos);
?>
<section class="admin-menu admin-menu--form admin-page">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Roles y permisos</span>
            <h2 class="admin-page__title"><?php echo $h($title ?? 'Rol'); ?></h2>
            <p class="admin-page__subtitle">
                <?php echo $esSistema
                    ? 'Rol de sistema: tiene todos los módulos con edición y no se modifica.'
                    : 'Elige a qué módulos entra este rol y si sólo consulta o también hace cambios.'; ?>
            </p>
        </div>
        <div class="admin-menu__actions admin-actions">
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="/admin/roles">
                <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                Volver
            </a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <form method="POST" action="<?php echo $h($action ?? ''); ?>">
        <input type="hidden" name="admin_csrf" value="<?php echo $h($adminCsrfToken ?? ''); ?>">

        <section class="admin-menu__panel admin-panel admin-card">
            <div class="admin-menu__panel-head">
                <div>
                    <h3>Datos del rol</h3>
                    <p>
                        <?php if ($usuarios > 0) : ?>
                            <?php echo $usuarios === 1 ? 'Un usuario tiene' : $usuarios . ' usuarios tienen'; ?> este rol: los cambios le aplican desde su siguiente acción, sin cerrar su sesión.
                        <?php else : ?>
                            Ningún usuario tiene este rol todavía.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="admin-form-grid">
                <div class="admin-field">
                    <label class="admin-field__label" for="rol-nombre">Nombre</label>
                    <input type="text" id="rol-nombre" name="nombre" maxlength="60" required
                           value="<?php echo $h($datos['nombre'] ?? ''); ?>"
                           <?php echo $soloLectura ? 'disabled' : ''; ?>>
                    <p class="admin-field__hint">Como se verá en Usuarios, por ejemplo «Capitán de meseros» o «Contador».</p>
                </div>
                <div class="admin-field">
                    <label class="admin-field__label" for="rol-descripcion">Descripción</label>
                    <input type="text" id="rol-descripcion" name="descripcion" maxlength="255"
                           value="<?php echo $h($datos['descripcion'] ?? ''); ?>"
                           <?php echo $soloLectura ? 'disabled' : ''; ?>>
                    <p class="admin-field__hint">Opcional: para qué es el rol.</p>
                </div>
            </div>
        </section>

        <?php foreach ($grupos as $grupo => $infoGrupo) : ?>
            <section class="admin-menu__panel admin-panel admin-card">
                <div class="admin-menu__panel-head">
                    <div>
                        <h3><?php echo $h($infoGrupo['titulo']); ?></h3>
                        <p><?php echo $h($infoGrupo['detalle']); ?></p>
                    </div>
                </div>

                <div class="admin-table-wrap" data-scrollable>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Módulo</th>
                                <th scope="col">Acceso</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (Permisos::MODULOS as $clave => $modulo) : ?>
                                <?php if ($modulo['grupo'] !== $grupo) { continue; } ?>
                                <?php
                                $actual = $esSistema ? Permisos::EDITAR : (string) ($permisos[$clave] ?? '');
                                $tope = $topeActor[$clave] ?? null;
                                $idGrupo = 'permiso-' . $clave;
                                ?>
                                <tr>
                                    <td>
                                        <span class="admin-table__cell-main" id="<?php echo $h($idGrupo); ?>"><?php echo $h($modulo['titulo']); ?></span>
                                        <span class="admin-table__cell-sub"><?php echo $h($modulo['detalle']); ?></span>
                                    </td>
                                    <td>
                                        <fieldset class="admin-pills" aria-labelledby="<?php echo $h($idGrupo); ?>">
                                            <div class="admin-pills__group">
                                                <?php foreach ($opciones as $valor => $opcion) : ?>
                                                    <?php
                                                    $valor = (string) $valor;
                                                    // Por encima del tope de quien edita no se ofrece.
                                                    $fueraDeTope = $valor !== '' && ($tope === null || !Permisos::alcanza($tope, $valor));
                                                    $deshabilitada = $soloLectura || $fueraDeTope;
                                                    ?>
                                                    <label class="admin-pill"<?php echo $fueraDeTope && !$soloLectura ? ' title="Tu rol no tiene este permiso: no puedes darlo"' : ''; ?>>
                                                        <input type="radio"
                                                               name="permisos[<?php echo $h($clave); ?>]"
                                                               value="<?php echo $h($valor); ?>"
                                                               <?php echo $actual === $valor ? 'checked' : ''; ?>
                                                               <?php echo $deshabilitada ? 'disabled' : ''; ?>>
                                                        <span class="admin-pill__body">
                                                            <span class="admin-pill__title"><?php echo $h($opcion['titulo']); ?></span>
                                                            <span class="admin-pill__hint"><?php echo $h($opcion['hint']); ?></span>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </fieldset>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="admin-menu__panel admin-panel admin-card">
            <div class="admin-menu__panel-head">
                <div>
                    <h3>Cómo entra este rol</h3>
                    <p>
                        <?php if ($acceso === Permisos::ACCESO_CONTRASENA) : ?>
                            Con usuario y contraseña, porque tiene módulos del panel.
                        <?php elseif ($acceso === Permisos::ACCESO_NIP) : ?>
                            Con NIP desde la tablet, porque sólo tiene pantallas de piso.
                        <?php else : ?>
                            Todavía no tiene módulos: sin al menos uno, no podrá entrar.
                        <?php endif; ?>
                        La vía de acceso la deciden los módulos.
                        <?php if ($usuarios > 0) : ?>
                            Como el rol ya tiene usuarios, no puede pasar de contraseña a NIP ni al revés: sus cuentas tienen la credencial de su vía actual.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </section>

        <?php if (!$soloLectura) : ?>
            <div class="admin-form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?php echo $h($accion ?? 'Guardar'); ?></button>
                <a class="admin-btn admin-btn--secondary" href="/admin/roles">Cancelar</a>
            </div>
        <?php endif; ?>
    </form>
</section>
