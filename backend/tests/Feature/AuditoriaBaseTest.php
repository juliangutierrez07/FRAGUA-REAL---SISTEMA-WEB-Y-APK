<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Categoria;
use App\Models\Mesa;
use App\Models\Producto;
use App\Models\User;
use App\Services\AuditoriaService;
use App\Services\CajaService;
use App\Services\PagoService;
use App\Services\PedidoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 0, tarea 7 del plan de implementación: base de auditoría.
 * RNF-TRANS-020/021/022, RF-M01-017, RF-M01-024, RF-M06-020, RNF-M02-003.
 */
class AuditoriaBaseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $mesero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role' => 'administrador', 'nombre' => 'Admin', 'email' => 'admin@test.co', 'password' => 'secreto123', 'activo' => true]);
        $this->mesero = User::create(['role' => 'mesero', 'nombre' => 'Mesero', 'email' => 'mesero@test.co', 'password' => 'secreto123', 'activo' => true]);
    }

    public function test_RNF_TRANS_021_cada_registro_guarda_quien_que_y_desde_donde(): void
    {
        Sanctum::actingAs($this->admin);

        $this->withHeader('User-Agent', 'Prueba/1.0')
            ->postJson("/api/usuarios/{$this->mesero->id}/desactivar")
            ->assertOk();

        $registro = Auditoria::where('accion', 'desactivar_usuario')->sole();
        $this->assertSame($this->admin->id, $registro->user_id);
        $this->assertSame('User', $registro->entidad);
        $this->assertSame($this->mesero->id, $registro->entidad_id);
        $this->assertNotNull($registro->ip);
        $this->assertSame('Prueba/1.0', $registro->user_agent);
    }

    public function test_RNF_TRANS_022_un_registro_de_auditoria_no_se_edita_ni_se_borra(): void
    {
        $registro = app(AuditoriaService::class)->registrar('prueba', 'Prueba', 1, ['x' => 1], $this->admin);

        try {
            $registro->update(['accion' => 'otra']);
            $this->fail('Se pudo editar un registro de auditoría.');
        } catch (\LogicException) {
        }

        try {
            $registro->delete();
            $this->fail('Se pudo borrar un registro de auditoría.');
        } catch (\LogicException) {
        }

        $this->assertSame('prueba', $registro->fresh()->accion);
    }

    public function test_RF_M06_020_caja_y_anulaciones_quedan_auditadas(): void
    {
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $caja = app(CajaService::class);
        $turno = $caja->abrir($this->admin, 50000);

        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'mesero', 'mesa_id' => $mesa->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);
        $pago = app(PagoService::class)->registrarCobro($pedido, $this->mesero, ['metodo' => 'efectivo', 'monto' => 20000]);
        app(PagoService::class)->anular($pago, $this->admin, 'Cobro equivocado');
        // 26/10/2026 (RF-M06-038): la caja solo cierra con todos los pedidos finalizados o cancelados.
        app(PedidoService::class)->cancelar($pedido->fresh(), $this->admin, 'El cliente se fue');
        $caja->cerrar($turno->fresh(), $this->admin, 49000, 'Faltó cambio');

        // Fase 5, tarea 1: anular el pago que completaba el pedido también anula su comprobante.
        $this->assertEqualsCanonicalizing(
            ['abrir_caja', 'anular_pago', 'anular_comprobante', 'cancelar_pedido', 'cerrar_caja'],
            Auditoria::pluck('accion')->all(),
        );
        $cierre = Auditoria::where('accion', 'cerrar_caja')->sole();
        $this->assertEquals(-1000, $cierre->detalles['diferencia']);
    }

    public function test_RNF_M02_003_cancelar_un_pedido_queda_auditado_con_su_motivo(): void
    {
        $categoria = Categoria::create(['nombre' => 'Hamburguesas']);
        $producto = Producto::create(['categoria_id' => $categoria->id, 'nombre' => 'Clásica', 'precio_base' => 20000, 'tipo_venta' => 'alimento']);
        $mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1']);
        $pedido = app(PedidoService::class)->crear([
            'tipo' => 'local', 'origen' => 'qr', 'mesa_id' => $mesa->id,
            'lineas' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ]);

        Sanctum::actingAs($this->mesero);
        $this->postJson("/api/pedidos/{$pedido->id}/cancelar", ['motivo' => 'El cliente se fue'])->assertOk();

        $registro = Auditoria::where('accion', 'cancelar_pedido')->sole();
        $this->assertSame('El cliente se fue', $registro->detalles['motivo']);
        $this->assertSame('pendiente', $registro->detalles['estado_anterior']);
    }
}
