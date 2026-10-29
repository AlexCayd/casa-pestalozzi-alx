<?php
$usuario = is_array($usuario ?? null) ? $usuario : [];
$baseUrl = (string) ($baseUrl ?? '/admin/usuarios');
$ctxActual = (string) ($contextoUsuarios ?? 'restaurante');
$nombreCtx = (string) ($contextoNombre ?? 'Restaurante');
$permitidos = is_array($ambitosPermitidos ?? null) ? $ambitosPermitidos : [];
$catalogos = is_array($catalogos ?? null) ? $catalogos : [];
$facultadesPorAmbito = is_array($facultadesPorAmbito ?? null) ? $facultadesPorAmbito : [];
$techosPorAmbito = is_array($techosPorAmbito ?? null) ? $techosPorAmbito : [];
$perfilesSugeridos = is_array($perfilesSugeridos ?? null) ? $perfilesSugeridos : [];
$identityEditable = !empty($identityEditable);
$puedeEditar = !empty($puedeEditar);
$esEdicion = !empty($usuario['id']);
$esSuper = !empty($esSuper) || !empty($usuario['es_superadmin']);
$ambitosTarget = is_array($usuario['ambitos'] ?? null) ? $usuario['ambitos'] : [];
$esc = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$normalizarModulos = static function (array $catalogo): array {
    $modulos = [];
    foreach ($catalogo as $clave => $info) {
        if (is_string($info)) {
            $modulos[(string) $clave] = ['titulo' => $info, 'grupo' => 'panel'];
            continue;
        }
        if (!is_array($info)) continue;
        $modulo = (string) ($info['modulo'] ?? $info['clave'] ?? (is_string($clave) ? $clave : ''));
        if ($modulo === '') continue;
        $modulos[$modulo] = [
            'titulo' => (string) ($info['titulo'] ?? $info['nombre'] ?? $modulo),
            'detalle' => (string) ($info['detalle'] ?? ''),
            'grupo' => (string) ($info['grupo'] ?? 'panel'),
        ];
    }
    return $modulos;
};
$tipoAccesoOriginal = (string) ($usuario['tipo_acceso'] ?? 'ninguno');
$panelLocal = false;
$pisoLocal = false;
foreach ($ambitosTarget as $scope) {
    foreach ((array) ($scope['permisos'] ?? []) as $modulo => $nivel) {
        if ($nivel === '' || $nivel === null) continue;
        $grupo = 'panel';
        foreach ($catalogos as $catalogo) {
            $defs = $normalizarModulos(is_array($catalogo) ? $catalogo : []);
            if (isset($defs[$modulo])) { $grupo = $defs[$modulo]['grupo']; break; }
        }
        if ($grupo === 'panel') $panelLocal = true;
        else $pisoLocal = true;
    }
}
$panelRemoto = !empty($usuario['panel_remoto']);
$tipoAcceso = ($panelLocal || $panelRemoto || $esSuper)
    ? 'contrasena'
    : ($pisoLocal ? 'nip' : 'ninguno');
$requierePassword = $tipoAcceso === 'contrasena' && (!$esEdicion || $tipoAccesoOriginal !== 'contrasena');
if ($esSuper) $tipoAcceso = 'contrasena';
$formData = $_POST ?? [];
?>
<section class="admin-menu admin-menu--form admin-page admin-users">
    <header class="admin-menu__header admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-menu__eyebrow admin-page__eyebrow">Usuarios · <?php echo $esc($nombreCtx); ?></span>
            <h2 class="admin-page__title"><?php echo $esEdicion ? 'Editar usuario' : 'Nuevo usuario'; ?></h2>
            <p class="admin-page__subtitle">Configura los ámbitos y permisos que necesita esta persona.</p>
        </div>
        <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light admin-back-button" href="<?php echo $esc($baseUrl); ?>">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
            Volver a usuarios
        </a>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <form class="admin-menu__form admin-users-form" method="POST" action="<?php echo $esc($baseUrl); ?>/<?php echo $esEdicion ? 'edit?id=' . (int) $usuario['id'] : 'create'; ?>" data-user-direct-form data-auth-type="<?php echo $esc($tipoAccesoOriginal); ?>" data-remote-panel="<?php echo $panelRemoto ? '1' : '0'; ?>" data-edit="<?php echo $esEdicion ? '1' : '0'; ?>" data-super="<?php echo $esSuper ? '1' : '0'; ?>" data-identity-editable="<?php echo $identityEditable && !$esSuper ? '1' : '0'; ?>">
        <input type="hidden" name="admin_csrf" value="<?php echo $esc($adminCsrfToken ?? ''); ?>">
        <?php if ($esEdicion) : ?><input type="hidden" name="id" value="<?php echo (int) $usuario['id']; ?>"><?php endif; ?>
        <?php if (!$puedeEditar) : ?><p class="admin-menu__empty" role="status">Tienes acceso de consulta. Para cambiar usuarios necesitas las facultades de gestión y delegación de permisos.</p><?php endif; ?>
        <fieldset class="admin-users-form__direct-fields" <?php echo $puedeEditar ? '' : 'disabled'; ?>>

        <section class="admin-menu__panel admin-menu__panel--form admin-users-panel admin-panel admin-card">
            <div class="admin-menu__panel-head"><div><h3>Datos generales</h3><p>La identidad pertenece a la cuenta; los permisos se asignan por ámbito.</p></div></div>
            <div class="admin-users-form__grid admin-users-form__grid--pair">
                <div class="admin-users-form__field">
                    <label for="direct-username">Nombre de usuario</label>
                    <?php if ($identityEditable && !$esSuper) : ?>
                        <input id="direct-username" name="username" type="text" maxlength="20" pattern="[A-Za-z0-9_]{3,20}" autocomplete="username" required value="<?php echo $esc($formData['username'] ?? $usuario['username'] ?? ''); ?>" aria-describedby="direct-username-help">
                    <?php else : ?><p class="admin-form__readonly"><?php echo $esc($usuario['username'] ?? ''); ?></p><?php endif; ?>
                    <p class="admin-users-form__hint" id="direct-username-help">3 a 20 letras, números o guion bajo.</p>
                </div>
                <div class="admin-users-form__field">
                    <label for="direct-name">Nombre</label>
                    <?php if ($identityEditable && !$esSuper) : ?>
                        <input id="direct-name" name="nombre" type="text" maxlength="120" required value="<?php echo $esc($formData['nombre'] ?? $usuario['nombre'] ?? ''); ?>">
                    <?php else : ?><p class="admin-form__readonly"><?php echo $esc($usuario['nombre'] ?? ''); ?></p><?php endif; ?>
                </div>
                <?php if ($esEdicion) : ?>
                    <div class="admin-users-form__field admin-users-access-status-card admin-users-state-card">
                        <span class="admin-users-form__field-label">Estado de la cuenta</span>
                        <?php if ($identityEditable && !$esSuper) : ?>
                            <input type="hidden" name="activo" value="0">
                            <label class="admin-users-form__toggle admin-users-access-status-card__control"><input type="checkbox" name="activo" value="1" <?php echo !empty($usuario['activo']) ? 'checked' : ''; ?>><span class="admin-users-form__toggle-track" aria-hidden="true"><span class="admin-users-form__toggle-thumb"></span></span><span class="admin-users-form__toggle-text">Cuenta activa</span></label>
                        <?php else : ?><p><?php echo !empty($usuario['activo']) ? 'Activa' : 'Inactiva'; ?></p><?php endif; ?>
                        <?php if (!$identityEditable) : ?><p class="admin-users-form__hint">Solo un gestor Global puede cambiar el estado de una cuenta compartida.</p><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($esSuper) : ?><div class="admin-users-form__field admin-users-access-status-card"><span class="admin-users-form__field-label">Cuenta protegida</span><p class="admin-users-form__hint">La excepción de superadministrador se conserva y no se puede asignar desde este formulario.</p></div><?php endif; ?>
            </div>
        </section>

        <?php if (!$esSuper && $permitidos !== [] && $perfilesSugeridos !== []) : ?>
            <?php /* Sin JavaScript el perfil no puede rellenar nada: la sección nace oculta
                     y users-directos.js la muestra. El servidor ignora perfil_sugerido. */ ?>
            <section class="admin-menu__panel admin-menu__panel--form admin-users-panel admin-panel admin-card" data-user-presets hidden>
                <div class="admin-menu__panel-head"><div><h3>Perfil sugerido</h3><p>Rellena ámbitos, módulos y facultades de un solo paso. Puedes ajustarlos antes de guardar.</p></div></div>
                <fieldset class="admin-pills">
                    <legend class="admin-pills__legend">Tipo de usuario</legend>
                    <div class="admin-pills__group">
                        <?php foreach ($perfilesSugeridos as $clavePerfil => $perfilSugerido) : ?>
                            <label class="admin-pill">
                                <input type="radio" name="perfil_sugerido" value="<?php echo $esc($clavePerfil); ?>" data-user-preset="<?php echo $esc(json_encode($perfilSugerido['ambitos'], JSON_UNESCAPED_UNICODE)); ?>">
                                <span class="admin-pill__body">
                                    <span class="admin-pill__title"><?php echo $esc($perfilSugerido['titulo']); ?></span>
                                    <span class="admin-pill__hint"><?php echo $esc($perfilSugerido['detalle']); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <label class="admin-pill">
                            <input type="radio" name="perfil_sugerido" value="personalizado" data-user-preset-custom>
                            <span class="admin-pill__body">
                                <span class="admin-pill__title">Personalizado</span>
                                <span class="admin-pill__hint">Elige cada ámbito y módulo a mano.</span>
                            </span>
                        </label>
                    </div>
                </fieldset>
                <p class="admin-users-form__hint" data-user-preset-status role="status" aria-live="polite">Elegir un perfil reemplaza la selección actual. Solo se asignan los niveles y facultades que tú puedes delegar.</p>
            </section>
        <?php endif; ?>

        <section class="admin-menu__panel admin-menu__panel--form admin-users-panel admin-panel admin-card">
            <div class="admin-menu__panel-head"><div><h3>Ámbitos y módulos</h3><p>La ausencia de permiso significa que no hay acceso a ese módulo.</p></div></div>
            <?php if ($esSuper) : ?>
                <p class="admin-menu__empty">El superadministrador tiene acceso organizacional protegido. Sus ámbitos no se editan aquí.</p>
            <?php elseif ($permitidos === []) : ?>
                <p class="admin-menu__empty">No tienes facultades para asignar ámbitos.</p>
            <?php else : ?>
                <div class="admin-users-direct-scopes" data-direct-scopes>
                    <?php foreach ($permitidos as $slug) :
                        $nombreScope = \Services\Security\Contextos::NOMBRES[$slug] ?? ucfirst($slug);
                        $scope = is_array($ambitosTarget[$slug] ?? null) ? $ambitosTarget[$slug] : [];
                        $scopeActivo = !empty($scope['activo']);
                        $scopePerms = is_array($scope['permisos'] ?? null) ? $scope['permisos'] : [];
                        $scopeFacultades = is_array($scope['facultades'] ?? null) ? $scope['facultades'] : [];
                        $modulos = $normalizarModulos(is_array($catalogos[$slug] ?? null) ? $catalogos[$slug] : []);
                        $posted = is_array($formData['ambitos'][$slug] ?? null) ? $formData['ambitos'][$slug] : [];
                        $selected = array_key_exists('ambitos', $formData) ? !empty($posted['activo']) : $scopeActivo;
                    ?>
                        <fieldset class="admin-users-form__section admin-user-scope" data-user-scope data-scope="<?php echo $esc($slug); ?>">
                            <legend><?php echo $esc($nombreScope); ?></legend>
                            <input type="hidden" name="ambitos[<?php echo $esc($slug); ?>][activo]" value="0">
                            <label class="admin-users-form__toggle"><input type="checkbox" name="ambitos[<?php echo $esc($slug); ?>][activo]" value="1" data-scope-toggle <?php echo $selected ? 'checked' : ''; ?>><span class="admin-users-form__toggle-track" aria-hidden="true"><span class="admin-users-form__toggle-thumb"></span></span><span class="admin-users-form__toggle-text">Dar acceso a <?php echo $esc($nombreScope); ?></span></label>
                            <?php if ($modulos === []) : ?>
                                <p class="admin-users-form__hint">No hay módulos disponibles en este ámbito.</p>
                            <?php else : ?>
                                <div class="admin-table-wrap">
                                    <table class="admin-table admin-table--compact">
                                        <thead><tr><th>Módulo</th><th>Nivel de acceso</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($modulos as $clave => $modulo) :
                                            $piso = $modulo['grupo'] === 'piso';
                                            $value = array_key_exists($clave, (array) ($posted['permisos'] ?? [])) ? $posted['permisos'][$clave] : ($scopePerms[$clave] ?? '');
                                            $techo = $techosPorAmbito[$slug] ?? [];
                                            $nivelActor = is_array($techo) ? ($techo[$clave] ?? null) : ($techo === '*' ? '*' : null);
                                            $niveles = $piso ? ['operar'] : ['ver', 'editar'];
                                            $permitidosNiveles = $nivelActor === '*' ? $niveles : ($nivelActor === 'editar' ? ['ver', 'editar'] : ($nivelActor === 'ver' ? ['ver'] : ($nivelActor === 'operar' ? ['operar'] : [])));
                                            $nivelProtegido = $value !== '' && !in_array($value, $permitidosNiveles, true);
                                        ?>
                                            <tr data-module-row data-group="<?php echo $piso ? 'piso' : 'panel'; ?>">
                                                <td><strong><?php echo $esc($modulo['titulo']); ?></strong><?php if ($modulo['detalle'] !== '') : ?><span class="admin-table__cell-sub"><?php echo $esc($modulo['detalle']); ?></span><?php endif; ?></td>
                                                <td><label class="admin-sr-only" for="scope-<?php echo $esc($slug . '-' . $clave); ?>">Nivel para <?php echo $esc($modulo['titulo']); ?> en <?php echo $esc($nombreScope); ?></label>
                                                    <select id="scope-<?php echo $esc($slug . '-' . $clave); ?>" <?php echo $nivelProtegido ? '' : 'name="ambitos[' . $esc($slug) . '][permisos][' . $esc($clave) . ']"'; ?> data-module-level data-module="<?php echo $esc($clave); ?>" data-max-level="<?php echo $piso ? 'operar' : 'editar'; ?>" <?php echo $nivelProtegido ? 'data-protected="1" disabled aria-describedby="scope-note-' . $esc($slug . '-' . $clave) . '"' : ''; ?>>
                                                        <option value="" <?php echo $value === '' ? 'selected' : ''; ?>>Sin acceso</option>
                                                        <?php foreach ($permitidosNiveles as $nivelPermitido) : ?><option value="<?php echo $esc($nivelPermitido); ?>" <?php echo $value === $nivelPermitido ? 'selected' : ''; ?>><?php echo $nivelPermitido === 'operar' ? 'Operativo' : ($nivelPermitido === 'ver' ? 'Lectura' : 'Edición'); ?></option><?php endforeach; ?>
                                                        <?php if ($nivelProtegido) : ?><option value="<?php echo $esc($value); ?>" selected>Asignación protegida</option><?php endif; ?>
                                                    </select>
                                                    <?php if ($nivelProtegido) : ?><input type="hidden" name="ambitos[<?php echo $esc($slug); ?>][permisos][<?php echo $esc($clave); ?>]" value="<?php echo $esc($value); ?>"><span id="scope-note-<?php echo $esc($slug . '-' . $clave); ?>" class="admin-table__cell-sub">Asignación protegida: no tienes nivel para cambiarla.</span><?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            <?php $facultadesScope = is_array($facultadesPorAmbito[$slug] ?? null) ? $facultadesPorAmbito[$slug] : []; ?>
                            <?php if ($facultadesScope !== []) : ?>
                                <fieldset class="admin-users-form__section">
                                    <legend>Facultades especiales</legend>
                                    <div class="admin-users-form__grid">
                                        <?php foreach ($facultadesScope as $facultad => $label) : ?>
                                            <?php $checked = array_key_exists('ambitos', $formData)
                                                ? in_array((string) $facultad, (array) ($posted['facultades'] ?? []), true)
                                                : in_array((string) $facultad, $scopeFacultades, true); ?>
                                            <label class="admin-users-form__toggle"><input type="checkbox" name="ambitos[<?php echo $esc($slug); ?>][facultades][]" value="<?php echo $esc($facultad); ?>" <?php echo $checked ? 'checked' : ''; ?>><span class="admin-users-form__toggle-track" aria-hidden="true"><span class="admin-users-form__toggle-thumb"></span></span><span class="admin-users-form__toggle-text"><?php echo $esc($label); ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                            <?php endif; ?>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-menu__panel admin-menu__panel--form admin-users-panel admin-panel admin-card" data-user-credential-section>
            <div class="admin-menu__panel-head"><div><h3>Forma de acceso</h3><p>Se calcula automáticamente con el conjunto de permisos del usuario.</p></div></div>
            <p class="admin-tag admin-role admin-role--<?php echo $tipoAcceso === 'contrasena' ? 'admin' : ($tipoAcceso === 'nip' ? 'waiter' : 'cook'); ?>" data-user-credential-label><?php echo $tipoAcceso === 'contrasena' ? 'Contraseña requerida' : ($tipoAcceso === 'nip' ? 'Acceso operativo por NIP' : 'Sin acceso'); ?></p>
            <p class="admin-users-form__hint" data-user-credential-hint>Un permiso administrativo en cualquier ámbito requiere contraseña. El acceso solo operativo utiliza NIP y no abre el panel.</p>
            <?php if ($identityEditable && !$esSuper) : ?>
                <div class="admin-users-form__grid admin-users-form__grid--pair" data-user-password-fields>
                    <div class="admin-users-form__field"><label for="direct-password"><?php echo $esEdicion && !$requierePassword ? 'Nueva contraseña (opcional)' : 'Contraseña'; ?></label><input id="direct-password" name="password" type="password" autocomplete="new-password" minlength="8" pattern="(?=.*[A-Z])(?=.*\d).{8,}" <?php echo $requierePassword ? 'required' : ''; ?>><p class="admin-users-form__hint">Mínimo 8 caracteres, una mayúscula y un número. En edición, dejar en blanco conserva la contraseña.</p></div>
                    <div class="admin-users-form__field"><label for="direct-password-confirm">Confirmar contraseña</label><input id="direct-password-confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="8" pattern="(?=.*[A-Z])(?=.*\d).{8,}" <?php echo $requierePassword ? 'required' : ''; ?>></div>
                </div>
                <div class="admin-users-form__field" data-user-current-password <?php echo $esEdicion ? '' : 'hidden'; ?>><label for="direct-admin-password">Tu contraseña actual <span>(si se solicita al confirmar)</span></label><input id="direct-admin-password" name="admin_password" type="password" autocomplete="current-password"><p class="admin-users-form__hint">Puede ser necesaria para proteger cambios de credenciales.</p></div>
            <?php endif; ?>
            <?php if ($esEdicion && in_array($tipoAcceso, ['nip', 'ninguno'], true) && !$esSuper) : ?>
                <div class="admin-users-form__field">
                    <button type="submit" class="admin-btn admin-btn--secondary admin-btn--small" formaction="<?php echo $esc($baseUrl); ?>/regenerar-nip" formmethod="POST">Regenerar NIP</button>
                    <p class="admin-users-form__hint">El código actual no se puede consultar. El nuevo se mostrará una sola vez.</p>
                </div>
            <?php endif; ?>
        </section>

        </fieldset>
        <div class="admin-menu__form-actions admin-users-form__actions">
            <?php if (!$esSuper && $puedeEditar) : ?><button type="submit" class="admin-btn admin-btn--primary admin-menu__button admin-menu__button--primary"><?php echo $esEdicion ? 'Guardar cambios' : 'Crear usuario'; ?></button><?php endif; ?>
            <a class="admin-btn admin-btn--secondary admin-menu__button admin-menu__button--light" href="<?php echo $esc($baseUrl); ?>">Cancelar</a>
        </div>
    </form>
</section>

<?php if (is_array($nipFlash ?? null) && preg_match('/^\d{4}$/', (string) ($nipFlash['nip'] ?? ''))) : ?>
    <div hidden data-user-nip-delivery data-nip="<?php echo $esc($nipFlash['nip']); ?>" data-nip-visibility-seconds="<?php echo (int) \Services\Users\UsuarioConfig::NIP_MODAL_VISIBILIDAD_SEGUNDOS; ?>" data-after-url="<?php echo $esc($nipFlash['after_url'] ?? ''); ?>" data-flow="directo"></div>
<?php endif; ?>
