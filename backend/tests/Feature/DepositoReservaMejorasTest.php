<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\CajaTurno;
use App\Models\Mesa;
use App\Models\Reserva;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\PagoDirectoDePrueba;
use Tests\TestCase;

/**
 * Mejoras del 26/10/2026 (M05): el Cliente paga el depósito pendiente desde «Mis pedidos y reservas»
 * (RF-M05-044, RN-M05-25), y el depósito manual registra con cuánto pagó el cliente en efectivo y el cambio
 * (RF-M05-045, M06 RF-M06-036) o la foto del comprobante (RF-M05-046, RN-M05-26).
 */
class DepositoReservaMejorasTest extends TestCase
{
    use PagoDirectoDePrueba;
    use RefreshDatabase;

    private User $cliente;
    private User $mesero;
    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $crear = fn (string $rol, string $correo) => User::create([
            'role' => $rol, 'nombre' => ucfirst($rol), 'email' => $correo, 'password' => 'Clave12345', 'activo' => true,
        ]);
        $this->cliente = $crear('cliente', 'laura@correo.co');
        $this->mesero = $crear('mesero', 'mesero@test.co');
        $admin = $crear('administrador', 'admin@test.co');
        $this->mesa = Mesa::create(['nombre' => 'Mesa 1', 'qr_code' => 'qr-1', 'estado' => 'libre']);
        CajaTurno::create([
            'fecha' => now('America/Bogota')->toDateString(), 'base_inicial' => 0, 'efectivo_esperado' => 0,
            'abierto_por' => $admin->id, 'abierto_at' => now(), 'estado' => 'abierta',
        ]);
    }

    private function reserva(string $correo = 'laura@correo.co'): int
    {
        return $this->postJson('/api/reservas', [
            'mesa_id' => $this->mesa->id, 'cliente_nombre' => 'Laura', 'cliente_telefono' => '3105551234', 'cliente_correo' => $correo,
            'fecha_hora' => CarbonImmutable::now('America/Bogota')->addDay()->setTime(20, 0)->format('Y-m-d H:i'),
        ])->assertCreated()->json('id');
    }

    public function test_RF_M05_044_RN_M05_25_el_cliente_paga_el_deposito_pendiente_desde_mis_reservas(): void
    {
        $id = $this->reserva();
        $ajena = $this->reserva('otra@correo.co');

        Sanctum::actingAs($this->cliente);
        $this->getJson('/api/mis-reservas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.puede_pagar', true)
            ->assertJsonMissingPath('data.0.token_publico');

        $this->pagoDirecto("/api/mis-reservas/{$ajena}/deposito", ['pasarela' => 'nequi'])->assertNotFound();
        $this->pagoDirecto("/api/mis-reservas/{$id}/deposito", ['pasarela' => 'nequi'])
            ->assertOk()
            ->assertJsonPath('deposito_estado', 'pendiente')
            ->assertJsonPath('deposito_tiene_comprobante', true)
            ->assertJsonPath('puede_pagar', false)
            ->assertJsonPath('checkout', null);

        Storage::disk('local')->assertExists(Reserva::findOrFail($id)->deposito_comprobante_path);
        // Mientras el personal lo revisa, no se vuelve a pagar.
        $this->getJson('/api/mis-reservas')->assertJsonPath('data.0.puede_pagar', false);
        $this->pagoDirecto("/api/mis-reservas/{$id}/deposito", ['pasarela' => 'nequi'])->assertStatus(422);
    }

    public function test_RF_M05_045_RN_M05_26_el_deposito_manual_en_efectivo_guarda_lo_recibido_y_el_cambio(): void
    {
        Sanctum::actingAs($this->mesero);
        $id = $this->reserva();
        $valor = (float) Reserva::findOrFail($id)->deposito_valor;

        $this->postJson("/api/reservas/{$id}/deposito/manual", ['metodo' => 'efectivo', 'recibido' => $valor - 1000])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'no alcanza para el depósito'));
        $this->assertSame('pendiente_pago', Reserva::findOrFail($id)->estado);

        $this->postJson("/api/reservas/{$id}/deposito/manual", ['metodo' => 'efectivo', 'recibido' => $valor + 30000])
            ->assertOk()->assertJsonPath('estado', 'confirmada');

        $detalles = Auditoria::where('accion', 'registrar_deposito_reserva')->sole()->detalles;
        $this->assertEquals($valor + 30000, $detalles['recibido']);
        $this->assertEquals(30000, $detalles['cambio']);
    }

    public function test_RF_M05_046_el_deposito_manual_por_transferencia_exige_la_foto_y_el_personal_la_ve(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->mesero);
        $id = $this->reserva();

        $this->postJson("/api/reservas/{$id}/deposito/manual", ['metodo' => 'transferencia'])
            ->assertStatus(422)->assertJsonValidationErrors(['comprobante' => 'Toma la foto del comprobante de la transferencia.']);

        $this->post("/api/reservas/{$id}/deposito/manual", [
            'metodo' => 'transferencia', 'comprobante' => UploadedFile::fake()->image('comprobante.jpg', 600, 900),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('estado', 'confirmada')
            ->assertJsonPath('deposito_tiene_comprobante', true);

        Storage::disk('local')->assertExists(Reserva::findOrFail($id)->deposito_comprobante_path);
        $this->get("/api/reservas/{$id}/deposito/comprobante")->assertOk();
        $this->assertTrue(Auditoria::where('accion', 'registrar_deposito_reserva')->sole()->detalles['con_foto']);
    }
}
