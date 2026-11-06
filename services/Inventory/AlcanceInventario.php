<?php
declare(strict_types=1);
namespace Services\Inventory;

use Services\Security\PermisosUsuario;

/** El actor y nivel vienen de la sesión/coordinador servidor, nunca del payload. */
final class AlcanceInventario
{
    public function __construct(private \mysqli $db, private int $actorId, private string $authNivel) {}

    public function actorId(): int { return $this->actorId; }

    /** Venta de un negocio: permiso de piso independiente al módulo Inventario. */
    public function autorizarVenta(string $contexto, bool $bloquear = false): array
    {
        if (!in_array($contexto, ['restaurante', 'panaderia'], true)) throw new InventarioError('AMBITO_DENEGADO','Solo un negocio vendedor consume inventario por venta.');
        return $this->autorizarPiso($contexto, 'pdv', $bloquear);
    }

    /**
     * Operación de piso acotada a su módulo (`pdv`, `vitrina`). No abre el
     * módulo Inventario: quien la llama limita qué almacenes y artículos toca.
     */
    public function autorizarPiso(string $contexto, string $modulo, bool $bloquear = false): array
    {
        $perfil = PermisosUsuario::cargar($this->actorId, $this->db, $bloquear);
        $decision = PermisosUsuario::decidir($perfil, $contexto, $modulo, 'operar', $this->authNivel);
        if (!$decision['ok']) throw new InventarioError($decision['codigo'],'No tienes permiso para esta operación de piso.');
        return ['unidad_id'=>(int)$perfil['ambitos'][$contexto]['id'],'contexto'=>$contexto];
    }

    public function autorizar(string $contexto, string $nivel = 'ver', ?string $facultad = null, bool $bloquear = false): array
    {
        $perfil = PermisosUsuario::cargar($this->actorId, $this->db, $bloquear);
        $decision = PermisosUsuario::decidir($perfil, $contexto, 'inventario', $nivel, $this->authNivel);
        if (!$decision['ok']) throw new InventarioError($decision['codigo'], 'No tienes acceso a esta operación de inventario.');
        $ambito = $perfil['ambitos'][$contexto];
        if ($facultad !== null && !in_array($facultad, $ambito['facultades'], true)) {
            throw new InventarioError('FACULTAD_DENEGADA', 'No tienes la facultad requerida.');
        }
        return ['unidad_id' => (int)$ambito['id'], 'contexto' => $contexto];
    }

    public function almacen(string $contexto, int $id, string $nivel = 'ver', bool $bloquear = false, bool $exigirActivo = true): array
    {
        $alcance = $this->autorizar($contexto, $nivel, null, $bloquear);
        $stmt = $this->db->prepare("SELECT a.id,a.unidad_id,a.nombre,a.permitir_negativos,a.activo FROM almacenes a WHERE a.id=? AND a.unidad_id IN (SELECT id FROM unidades WHERE slug IN ('global','restaurante','panaderia'))" . ($bloquear ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || ((int)$row['unidad_id'] !== $alcance['unidad_id'] && !($contexto === 'global' && $nivel === 'ver'))) {
            throw new InventarioError('RECURSO_NO_ENCONTRADO', 'No se encontró el almacén autorizado.');
        }
        if ($exigirActivo && !(int)$row['activo']) throw new InventarioError('ALMACEN_INACTIVO', 'El almacén está inactivo.');
        return $row;
    }
}
