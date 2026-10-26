<?php

namespace App\Services;

use App\Models\Combo;
use App\Models\Extra;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reglas de M02 (Pedidos Locales) y M07 (Domicilios y Recogida).
 * ADR-001 (README de la base de datos): un solo Pedido para los 3 canales,
 * distinguidos por `tipo`, con las columnas de cada canal en NULL cuando no aplican.
 */
class PedidoService
{
    public function __construct(
        private readonly ComboService $comboService,
        private readonly PromocionService $promocionService,
    ) {
    }

    /**
     * Crea un pedido con sus líneas de detalle, congelando precios (ADR-002).
     *
     * $data espera:
     *  - tipo: local|domicilio|recogida
     *  - origen: mesero|qr|cliente_web
     *  - mesa_id (solo local)
     *  - cliente_id (opcional, cliente registrado) | cliente_invitado_nombre/telefono
     *  - telefono_contacto (domicilio/recogida; si no viene, el del invitado o el del perfil)
     *  - direccion_entrega/barrio/referencia/valor_domicilio (solo domicilio)
     *  - observaciones_entrega (domicilio/recogida, RF-M07-010)
     *  - metodo_pago_preferido contra_entrega|en_linea y pasarela_preferida (domicilio/recogida,
     *    RF-M07-013/015, RF-M07-R-007/008; por defecto contra entrega o al recoger)
     *  - lineas: array de ['producto_id'?, 'variante_id'?, 'combo_id'?, 'cantidad', 'extras_ids'?, 'observaciones'?]
     */
    public function crear(array $data): Pedido
    {
        $this->validarReglasDeCanal($data);

        $pedido = DB::transaction(function () use ($data) {
            $pedido = new Pedido();
            $pedido->tipo = $data['tipo'];
            $pedido->origen = $data['origen'];
            // RF-M06-005: con este token el invitado paga en línea y consulta su pedido.
            $pedido->token_publico = Str::random(48);
            $pedido->mesa_id = $data['mesa_id'] ?? null;
            // Mejora (26/10/2026): cuentas separadas en la mesa.
            $pedido->cuenta = $data['tipo'] === 'local' ? $this->cuentaDelPedido($data) : null;
            $pedido->cliente_id = $data['cliente_id'] ?? null;
            $pedido->cliente_invitado_nombre = $data['cliente_invitado_nombre'] ?? null;
            $pedido->cliente_invitado_telefono = $data['cliente_invitado_telefono'] ?? null;
            // Fase 4, tarea 5: correo opcional del invitado para avisarle (RF-M07-R-012). El Cliente
            // con cuenta usa el de su cuenta.
            $correoInvitado = mb_strtolower(trim((string) ($data['cliente_invitado_correo'] ?? '')));
            $pedido->cliente_invitado_correo = $data['tipo'] !== 'local' && empty($data['cliente_id']) && $correoInvitado !== ''
                ? $correoInvitado
                : null;
            $esRemoto = $data['tipo'] !== 'local';
            $pedido->telefono_contacto = $esRemoto ? $this->telefonoDeContacto($data) : null;
            // RN-M07-R-01: la recogida no lleva dirección ni barrio aunque el formulario los mande.
            $esDomicilio = $data['tipo'] === 'domicilio';
            $pedido->direccion_entrega = $esDomicilio ? trim($data['direccion_entrega']) : null;
            $pedido->barrio = $esDomicilio ? trim($data['barrio']) : null;
            $pedido->referencia = $esDomicilio ? trim($data['referencia']) : null;
            // Mejora (26/10/2026): la ubicación que compartió el Cliente, para abrirla en el mapa.
            $conUbicacion = $esDomicilio && isset($data['ubicacion_lat'], $data['ubicacion_lng']);
            $pedido->ubicacion_lat = $conUbicacion ? round((float) $data['ubicacion_lat'], 7) : null;
            $pedido->ubicacion_lng = $conUbicacion ? round((float) $data['ubicacion_lng'], 7) : null;
            $pedido->observaciones_entrega = $esRemoto ? (trim($data['observaciones_entrega'] ?? '') ?: null) : null;
            $pedido->metodo_pago_preferido = $esRemoto ? ($data['metodo_pago_preferido'] ?? 'contra_entrega') : null;
            $pedido->pasarela_preferida = $pedido->metodo_pago_preferido === 'en_linea' ? ($data['pasarela_preferida'] ?? null) : null;
            // Mejora (26/10/2026): el billete con el que paga el domicilio contra entrega.
            $pedido->paga_con = $esDomicilio && $pedido->metodo_pago_preferido === 'contra_entrega' && isset($data['paga_con'])
                ? round((float) $data['paga_con'], 2)
                : null;
            // RF-M07-012: se copia el valor vigente de domicilio al momento de crear el pedido.
            // La tabla `configuraciones` para editar ese valor vigente sigue pendiente (ver README
            // de la base de datos) — mientras tanto el valor vigente se recibe como parámetro.
            $pedido->valor_domicilio = $data['tipo'] === 'domicilio' ? ($data['valor_domicilio'] ?? 0) : null;
            $pedido->estado = 'pendiente';
            $pedido->subtotal = 0;
            $pedido->total = 0;
            // RF-M04-035 (Fase 2, tarea 1): las promociones automáticas vigentes al crear el pedido
            // quedan guardadas con él, como los precios (ADR-002).
            $pedido->promociones_vigentes = $this->promocionService->automaticasVigentes();
            $pedido->save();

            foreach ($data['lineas'] as $linea) {
                $this->crearLinea($pedido, $linea);
            }

            $this->recalcularTotales($pedido);

            if ($pedido->paga_con !== null && (float) $pedido->paga_con < (float) $pedido->total) {
                throw new RuntimeException('El billete con el que pagas ($'.number_format((float) $pedido->paga_con, 0, ',', '.')
                    .') no alcanza para el total ($'.number_format((float) $pedido->total, 0, ',', '.').'). Elige uno mayor o «Pago exacto».');
            }

            // RN-M05-04: el primer pedido local de una mesa la marca ocupada (venía de una
            // mesa libre o reservada — de cualquier forma, ya hay alguien sentado ahí).
            if ($pedido->tipo === 'local' && $pedido->mesa_id) {
                $mesa = Mesa::find($pedido->mesa_id);
                // Fase 4, tarea 3: también cuenta como llegada si la mesa seguía Libre pero tiene una
                // reserva confirmada dentro de su ventana (el proceso de cada minuto aún no la marcaba).
                $estabaReservada = $mesa?->estado === 'reservada'
                    || ($mesa?->estado === 'libre' && app(ReservaService::class)->reservaEnVentana($mesa->id) !== null);
                if ($mesa && $mesa->estado !== 'ocupada') {
                    // La visita empieza con este pedido: así siempre queda dentro de la cuenta de la
                    // mesa, aunque el reloj cambie de segundo entre guardar el pedido y ocupar la mesa.
                    $mesa->ocupada_desde = $pedido->created_at;
                    $mesa->ocupar();
                }

                // Fase 2, tarea 2 (RF-M05-034, RN-M05-19): el depósito de la reserva se abona a los
                // pedidos de la visita; el primer pedido en una mesa reservada es la llegada.
                app(AbonoDepositoService::class)->alCrearPedidoLocal($pedido, $estabaReservada);
            }

            return $pedido->fresh(['detalles.extras', 'mesa']);
        });

        // M11 (21/10/2026): un pedido que llega por la web o por el QR avisa al personal.
        app(NotificacionService::class)->pedidoNuevo($pedido);

        return $pedido;
    }

    /**
     * Mejora (26/10/2026): a qué cuenta de la mesa va un pedido local. Con la mesa libre es la 1. Con la
     * mesa ocupada, una cuenta que ya existe en la visita o la siguiente (cuenta nueva, separada). Sin
     * número (el QR del Cliente, o el Mesero en una mesa con una sola cuenta) va a la 1.
     */
    private function cuentaDelPedido(array $data): int
    {
        $mesa = Mesa::whereKey($data['mesa_id'])->lockForUpdate()->first();
        $existentes = $mesa ? $mesa->cuentasDeLaVisita() : [];
        $pedida = isset($data['cuenta']) ? (int) $data['cuenta'] : null;

        if ($pedida === null || $existentes === []) {
            return 1;
        }
        $siguiente = max($existentes) + 1;
        if (! in_array($pedida, $existentes, true) && $pedida !== $siguiente) {
            throw new InvalidArgumentException("La cuenta {$pedida} no existe en esta mesa. Elige una de sus cuentas o abre la cuenta {$siguiente}.");
        }

        return $pedida;
    }

    private function validarReglasDeCanal(array $data): void
    {
        if (empty($data['lineas'])) {
            throw new InvalidArgumentException('El pedido debe tener al menos una línea.');
        }

        switch ($data['tipo'] ?? null) {
            case 'local':
                if (empty($data['mesa_id'])) {
                    throw new InvalidArgumentException('Un pedido local requiere mesa_id.');
                }
                // RF-M02-L-002, RN-PED-02 (también lo valida StorePedidoRequest; se repite por si
                // el servicio se llama fuera de una petición HTTP).
                if (! Mesa::where('id', $data['mesa_id'])->where('activa', true)->exists()) {
                    throw new InvalidArgumentException('La mesa no existe o está desactivada.');
                }
                break;
            case 'domicilio':
                // RN-M07-01, RF-M07-003 a 007, HU-M07-003: nombre, teléfono, dirección, barrio y
                // referencia son obligatorios. CORREGIDO (Fase 1, 11/10/2026): antes solo se
                // exigía la dirección; el barrio y la referencia eran opcionales.
                foreach (['direccion_entrega' => 'la dirección', 'barrio' => 'el barrio', 'referencia' => 'un punto de referencia'] as $campo => $nombre) {
                    if (trim((string) ($data[$campo] ?? '')) === '') {
                        throw new InvalidArgumentException("Un domicilio requiere {$nombre}.");
                    }
                }
                $this->validarDatosCliente($data);
                break;
            case 'recogida':
                $this->validarDatosCliente($data);
                break;
            default:
                throw new InvalidArgumentException('tipo de pedido inválido.');
        }
    }

    // Cliente invitado solo aplica a domicilio/recogida (regla de negocio del proyecto);
    // el QR de mesa (canal local) siempre requiere estar autenticado o identificado por mesa.
    private function validarDatosCliente(array $data): void
    {
        $tieneCliente = ! empty($data['cliente_id']);
        $tieneInvitado = ! empty($data['cliente_invitado_nombre']) && ! empty($data['cliente_invitado_telefono']);

        if (! $tieneCliente && ! $tieneInvitado) {
            throw new InvalidArgumentException('Se requiere cliente_id o datos de cliente invitado (nombre y teléfono).');
        }
        // RF-M07-004, RF-M07-R-003: siempre hay un teléfono para llamar al cliente.
        if ($this->telefonoDeContacto($data) === null) {
            throw new InvalidArgumentException('Falta un teléfono de contacto para el pedido.');
        }
    }

    // El que se escribió para este pedido; si no, el del invitado; si no, el del perfil del Cliente.
    private function telefonoDeContacto(array $data): ?string
    {
        $telefono = trim((string) ($data['telefono_contacto'] ?? $data['cliente_invitado_telefono'] ?? ''));
        if ($telefono === '' && ! empty($data['cliente_id'])) {
            $telefono = trim((string) User::whereKey($data['cliente_id'])->value('telefono'));
        }

        return $telefono === '' ? null : $telefono;
    }

    /**
     * Valida una línea pedida y calcula su precio vigente, sin guardar nada. Lo usan crearLinea()
     * y la cotización (cotizar()), para que el Cliente vea exactamente lo que se le cobrará.
     *
     * @return array{producto_id: ?int, variante_id: ?int, combo_id: ?int, categoria_id: ?int, cantidad: int, precio_unitario: float, extras: \Illuminate\Support\Collection<int, Extra>, observaciones: ?string}
     */
    private function prepararLinea(array $linea): array
    {
        $cantidad = max(1, (int) ($linea['cantidad'] ?? 1));
        $precioUnitario = 0;
        $productoId = $linea['producto_id'] ?? null;
        $varianteId = $linea['variante_id'] ?? null;
        $comboId = $linea['combo_id'] ?? null;
        $categoriaId = null;

        if ($comboId) {
            $combo = Combo::findOrFail($comboId);
            // RF-M04-031, RN-M04-16: además de componentes disponibles, el combo debe estar
            // vigente hoy (fechas, franja y días). Antes solo se revisaba lo primero.
            if (! $this->comboService->disponibleParaPedido($combo)) {
                throw new RuntimeException("El combo \"{$combo->nombre}\" no está disponible en este momento.");
            }
            if ($varianteId) {
                throw new InvalidArgumentException('Un combo no lleva variante.');
            }
            $precioUnitario = (float) $combo->precio;
            // Una línea de combo no lleva producto propio (los componentes van en el combo).
            $productoId = null;
        } elseif ($productoId) {
            $producto = Producto::findOrFail($productoId);
            if (! $producto->puedeAgregarseAPedido()) {
                throw new RuntimeException("El producto \"{$producto->nombre}\" no está disponible.");
            }
            $categoriaId = $producto->categoria_id;
            $precioUnitario = (float) $producto->precio_base;
            if ($varianteId) {
                // RNF-M04-004: la variante tiene que ser de ESTE producto y estar activa. Antes
                // se aceptaba cualquiera y su precio reemplazaba el del producto, así que se
                // podía pedir una hamburguesa doble al precio de la variante más barata de otro.
                $variante = Variante::where('id', $varianteId)->where('producto_id', $producto->id)->first();
                if (! $variante || ! $variante->activa) {
                    throw new InvalidArgumentException("La variante elegida no está disponible para \"{$producto->nombre}\".");
                }
                // RF-M04-018 (Fase 1): una variante agotada tampoco se vende.
                if (! $variante->puedeVenderse()) {
                    throw new RuntimeException("\"{$producto->nombre} ({$variante->nombre})\" está agotado.");
                }
                $precioUnitario = (float) $variante->precio;
            } elseif ($producto->tieneVariantes()) {
                // Fase 7, tarea 1 (RN-M04-04): un producto con presentaciones se pide con una de
                // ellas. Antes se aceptaba sin presentación, al precio base, que puede ser menor.
                throw new InvalidArgumentException("Elige la presentación de \"{$producto->nombre}\".");
            }
        } else {
            throw new InvalidArgumentException('Cada línea requiere producto_id o combo_id.');
        }

        $extras = collect();
        foreach ($linea['extras_ids'] ?? [] as $extraId) {
            $extra = Extra::findOrFail($extraId);
            // RN-M04-05: un extra específico solo acompaña a su propio producto. RF-M04-011: un extra
            // general solo acompaña a los productos a los que se asoció. En ambos casos, activo.
            if (! $extra->aplicaA($productoId ? (int) $productoId : null)) {
                throw new InvalidArgumentException("El extra \"{$extra->nombre}\" no está disponible para este producto.");
            }
            $extras->push($extra);
        }

        return [
            'producto_id' => $productoId,
            'variante_id' => $varianteId,
            'combo_id' => $comboId,
            'categoria_id' => $categoriaId,
            'cantidad' => $cantidad,
            'precio_unitario' => $precioUnitario,
            'extras' => $extras,
            'observaciones' => $linea['observaciones'] ?? null,
        ];
    }

    private function crearLinea(Pedido $pedido, array $linea): PedidoDetalle
    {
        $datos = $this->prepararLinea($linea);

        $detalle = new PedidoDetalle();
        $detalle->pedido_id = $pedido->id;
        $detalle->producto_id = $datos['producto_id'];
        $detalle->variante_id = $datos['variante_id'];
        $detalle->combo_id = $datos['combo_id'];
        $detalle->cantidad = $datos['cantidad'];
        $detalle->precio_unitario_congelado = $datos['precio_unitario'];
        $detalle->descuento = 0;
        $detalle->preparado = false;
        $detalle->observaciones = $datos['observaciones'];
        $detalle->save();

        foreach ($datos['extras'] as $extra) {
            $detalle->extras()->attach($extra->id, ['precio_congelado' => $extra->precio]);
        }

        return $detalle;
    }

    /**
     * RF-M04-035 (Fase 2, tarea 1): lo que costaría el pedido, con las promociones vigentes
     * ahora mismo, sin crearlo. El Cliente lo ve en el carrito y el Mesero al armar el pedido.
     *
     * @return array{subtotal: float, descuento: float, promociones: array, valor_domicilio: float, total: float, lineas: array}
     */
    public function cotizar(array $lineas, float $valorDomicilio = 0): array
    {
        $datos = [];
        foreach (array_values($lineas) as $i => $linea) {
            $datos[$i] = $this->prepararLinea($linea);
        }
        $descriptores = array_map(fn ($d) => [
            'producto_id' => $d['producto_id'],
            'variante_id' => $d['variante_id'],
            'categoria_id' => $d['categoria_id'],
            'es_combo' => $d['combo_id'] !== null,
            'cantidad' => $d['cantidad'],
            'precio_unitario' => $d['precio_unitario'],
            'extras_unitario' => (float) $d['extras']->sum('precio'),
        ], $datos);

        $calculo = $this->promocionService->calcular($descriptores, $this->promocionService->automaticasVigentes());
        $subtotal = array_sum(array_map(fn ($d) => ($d['precio_unitario'] + $d['extras_unitario']) * $d['cantidad'], $descriptores));

        return [
            'subtotal' => $subtotal,
            'descuento' => $calculo['total'],
            'promociones' => $calculo['aplicadas'],
            'valor_domicilio' => $valorDomicilio,
            'total' => $subtotal - $calculo['total'] + $valorDomicilio,
            'lineas' => array_map(fn ($i) => [
                'subtotal' => ($descriptores[$i]['precio_unitario'] + $descriptores[$i]['extras_unitario']) * $descriptores[$i]['cantidad'],
                'descuento' => $calculo['por_linea'][$i],
            ], array_keys($descriptores)),
        ];
    }

    // ───────────── Edición de un pedido (Fase 1, tarea 1 del plan, 10/10/2026) ─────────────
    // RN-PED-07/08/09/10/11/12 · RF-M02-L-004/005/012/013/014/015/016/017 · HU-M02-002/004/005/020
    // RF-M07-009/021/023/024/028 · RF-M07-R-005/016 (domicilio y recogida reutilizan lo de M02).
    //
    // Con el pedido PENDIENTE se agregan, cambian y retiran líneas libremente. Con el pedido
    // CONFIRMADO también, pero solo las líneas que la cocina no ha preparado. Cada cambio queda
    // en `pedido_modificaciones` y en la auditoría. Los cambios hechos ya confirmado van a la
    // cocina en una comanda de actualización (RN-PED-09, Fase 6: ComandaService).

    /** RF-M02-L-003/012: agrega una línea nueva al pedido, con el precio vigente congelado. */
    public function agregarLinea(Pedido $pedido, array $linea, User $actor): Pedido
    {
        return $this->modificar($pedido, $actor, function (Pedido $pedido) use ($linea) {
            $detalle = $this->crearLinea($pedido, $linea);
            $detalle->load(['producto', 'variante', 'combo', 'extras']);
            $resumen = $this->resumenLinea($detalle);

            return [
                'accion' => 'agregar_linea',
                'pedido_detalle_id' => $detalle->id,
                'descripcion' => "Agregó {$resumen['cantidad']} × {$resumen['nombre']}",
                'antes' => null,
                'despues' => $resumen,
            ];
        });
    }

    /** RF-M02-L-004/006: cambia la cantidad o las observaciones de una línea no preparada. */
    public function actualizarLinea(Pedido $pedido, PedidoDetalle $detalle, array $cambios, User $actor): Pedido
    {
        return $this->modificar($pedido, $actor, function (Pedido $pedido) use ($detalle, $cambios) {
            $detalle = $this->lineaModificable($pedido, $detalle);
            $antes = $this->resumenLinea($detalle);

            if (array_key_exists('cantidad', $cambios)) {
                $cantidad = (int) $cambios['cantidad'];
                if ($cantidad < 1) {
                    throw new InvalidArgumentException('La cantidad mínima es 1; para quitar el producto, retíralo.');
                }
                // Subir la cantidad de algo agotado sería venderlo de nuevo: se valida igual que al agregarlo.
                if ($cantidad > $detalle->cantidad) {
                    $this->validarQueSigueDisponible($detalle);
                }
                $detalle->cantidad = $cantidad;
            }
            if (array_key_exists('observaciones', $cambios)) {
                $detalle->observaciones = $cambios['observaciones'] ?: null;
            }
            if (! $detalle->isDirty()) {
                throw new InvalidArgumentException('No hay cambios para guardar en esta línea.');
            }
            $detalle->save();
            $despues = $this->resumenLinea($detalle);

            $partes = [];
            if ($antes['cantidad'] !== $despues['cantidad']) {
                $partes[] = "cantidad {$antes['cantidad']} → {$despues['cantidad']}";
            }
            if ($antes['observaciones'] !== $despues['observaciones']) {
                $partes[] = 'observaciones';
            }

            return [
                'accion' => 'cambiar_linea',
                'pedido_detalle_id' => $detalle->id,
                'descripcion' => "Cambió {$antes['nombre']}: ".implode(', ', $partes),
                'antes' => $antes,
                'despues' => $despues,
            ];
        });
    }

    /** RF-M02-L-005/014: retira una línea no preparada. El pedido no puede quedar vacío. */
    public function retirarLinea(Pedido $pedido, PedidoDetalle $detalle, User $actor): Pedido
    {
        return $this->modificar($pedido, $actor, function (Pedido $pedido) use ($detalle) {
            $detalle = $this->lineaModificable($pedido, $detalle);
            if ($pedido->detalles()->count() <= 1) {
                throw new RuntimeException('Es la única línea del pedido. Si el cliente ya no quiere nada, cancela el pedido con un motivo.');
            }
            $antes = $this->resumenLinea($detalle);
            $detalleId = $detalle->id;
            $detalle->delete(); // pedido_detalle_extra se borra en cascada

            return [
                'accion' => 'retirar_linea',
                'pedido_detalle_id' => $detalleId,
                'descripcion' => "Retiró {$antes['cantidad']} × {$antes['nombre']}",
                'antes' => $antes,
                'despues' => null,
            ];
        });
    }

    /**
     * Esqueleto común: bloquea el pedido, valida su estado, aplica el cambio, recalcula los
     * totales (RF-M02-L-007), revisa que no quede por debajo de lo ya pagado y deja el historial.
     *
     * @param  callable(Pedido): array  $cambio  devuelve accion, pedido_detalle_id, descripcion, antes, despues
     */
    private function modificar(Pedido $pedido, User $actor, callable $cambio): Pedido
    {
        return DB::transaction(function () use ($pedido, $actor, $cambio) {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if (! $pedido->admiteCambiosDeLineas()) {
                throw new RuntimeException(match ($pedido->estado) {
                    'preparando' => 'El pedido ya está en preparación: lo que falte va en un pedido nuevo.',
                    'finalizado' => 'Un pedido finalizado no se puede modificar (RN-PED-11).',
                    default => 'Un pedido cancelado no se puede modificar.',
                });
            }

            $totalAnterior = (float) $pedido->total;
            $registro = $cambio($pedido);
            $this->recalcularTotales($pedido);
            // Fase 2, tarea 2: si el pedido bajó por debajo del abono del depósito, el abono se ajusta.
            app(AbonoDepositoService::class)->reajustar($pedido, $actor);

            // RF-M06-011: lo pagado nunca puede superar el total.
            $comprometido = $pedido->montoComprometido();
            if ((float) $pedido->total < $comprometido) {
                throw new RuntimeException('Con este cambio el total quedaría por debajo de lo que ya se pagó ($'.number_format($comprometido, 0, ',', '.').'). Anula primero el pago que sobra.');
            }

            $modificacion = $pedido->modificaciones()->create($registro + [
                'user_id' => $actor->id,
                'estado_pedido' => $pedido->estado,
                'total_anterior' => $totalAnterior,
                'total_nuevo' => $pedido->total,
                'afecta_comanda' => $pedido->estado === 'confirmado', // RN-PED-09
            ]);

            // Fase 6, tarea 2 (RN-PED-09, RF-M07-022): lo que cambió con el pedido confirmado va a la
            // cocina en una comanda de actualización. Un fallo ahí no deshace el cambio.
            if ($modificacion->afecta_comanda) {
                app(ComandaService::class)->alModificar($pedido, $modificacion);
            }

            // RNF-M02-012: modificar un pedido también queda en la auditoría general.
            app(AuditoriaService::class)->registrar('modificar_pedido', 'Pedido', $pedido->id, [
                'accion' => $modificacion->accion,
                'descripcion' => $modificacion->descripcion,
                'total_anterior' => $totalAnterior,
                'total_nuevo' => (float) $pedido->total,
            ], $actor);

            return $pedido->fresh(\App\Http\Controllers\Api\PedidoController::CON_DETALLES);
        });
    }

    // RN-PED-10, RF-M02-L-015, RF-M07-023: una línea ya preparada no se retira, cambia ni modifica.
    private function lineaModificable(Pedido $pedido, PedidoDetalle $detalle): PedidoDetalle
    {
        if ((int) $detalle->pedido_id !== (int) $pedido->id) {
            throw new InvalidArgumentException('Esa línea no pertenece a este pedido.');
        }
        $detalle = PedidoDetalle::with(['producto', 'variante', 'combo', 'extras'])->findOrFail($detalle->id);
        if ($detalle->preparado) {
            throw new RuntimeException('Ese producto ya fue preparado: no se puede retirar ni cambiar (RN-PED-10).');
        }

        return $detalle;
    }

    private function validarQueSigueDisponible(PedidoDetalle $detalle): void
    {
        if ($detalle->combo_id) {
            if (! $this->comboService->disponibleParaPedido($detalle->combo)) {
                throw new RuntimeException("El combo \"{$detalle->combo->nombre}\" no está disponible en este momento.");
            }
        } elseif ($detalle->producto && ! $detalle->producto->puedeAgregarseAPedido()) {
            throw new RuntimeException("El producto \"{$detalle->producto->nombre}\" no está disponible.");
        } elseif ($detalle->variante && ! $detalle->variante->puedeVenderse()) {
            throw new RuntimeException("\"{$detalle->producto?->nombre} ({$detalle->variante->nombre})\" está agotado.");
        }
    }

    // RF-M02-L-007: subtotal = suma de las líneas con sus extras (precios congelados).
    // Fase 2, tarea 1 (RF-M04-035 a 038): descuento = promociones guardadas con el pedido, o el
    // descuento manual si lo hay (RN-M04-21); se reparte entre las líneas.
    // total = subtotal − descuento + valor del domicilio (el domicilio nunca se rebaja).
    private function recalcularTotales(Pedido $pedido): void
    {
        $detalles = $pedido->detalles()->with(['extras', 'producto:id,categoria_id'])->orderBy('id')->get();

        $descriptores = [];
        foreach ($detalles as $d) {
            $descriptores[$d->id] = [
                'producto_id' => $d->combo_id ? null : $d->producto_id,
                'variante_id' => $d->combo_id ? null : $d->variante_id,
                'categoria_id' => $d->combo_id ? null : $d->producto?->categoria_id,
                'es_combo' => $d->combo_id !== null,
                'cantidad' => (int) $d->cantidad,
                'precio_unitario' => (float) $d->precio_unitario_congelado,
                'extras_unitario' => (float) $d->extras->sum('pivot.precio_congelado'),
            ];
        }

        $reglas = $pedido->descuento_manual ? [$pedido->descuento_manual] : ($pedido->promociones_vigentes ?? []);
        $calculo = $this->promocionService->calcular($descriptores, $reglas);

        foreach ($detalles as $d) {
            $parte = $calculo['por_linea'][$d->id] ?? 0.0;
            if ((float) $d->descuento !== (float) $parte) {
                $d->descuento = $parte;
                $d->saveQuietly();
            }
        }

        $subtotal = $detalles->sum(fn (PedidoDetalle $d) => $d->subtotalLinea());
        $pedido->subtotal = $subtotal;
        $pedido->descuento = $calculo['total'];
        $pedido->promociones_aplicadas = $calculo['aplicadas'] ?: null;
        $pedido->total = $subtotal - $calculo['total'] + (float) ($pedido->valor_domicilio ?? 0);
        $pedido->save();
    }

    // ───────────── Descuento manual (Fase 2, tarea 1, 11/10/2026) ─────────────
    // RF-M04-036/037/039/041, RN-M04-18/21/22/23, HU-M04-015: el Mesero o el Administrador rebajan
    // un pedido (porcentaje o valor fijo) con un motivo. Reemplaza a las promociones automáticas
    // (no se acumulan) y queda en el historial del pedido y en la auditoría con usuario, motivo y
    // monto. También puede usarse una promoción de tipo "manual" que el Administrador dejó lista
    // (por ejemplo "Cortesía de la casa 10 %"): se toma su valor y su alcance.

    /**
     * @param  array{tipo_valor?: string, valor?: float|int|string, promocion_id?: int, motivo: string}  $datos
     */
    public function aplicarDescuentoManual(Pedido $pedido, array $datos, User $actor): Pedido
    {
        return $this->cambiarDescuento($pedido, $actor, function (Pedido $pedido) use ($datos) {
            if (! empty($datos['promocion_id'])) {
                $promocion = \App\Models\Promocion::findOrFail($datos['promocion_id']);
                if ($promocion->tipo !== 'manual' || ! $this->promocionService->vigente($promocion)) {
                    throw new RuntimeException("La promoción \"{$promocion->nombre}\" no está disponible para aplicarla a mano.");
                }
                $regla = $this->promocionService->regla($promocion);
            } else {
                $tipoValor = $datos['tipo_valor'] ?? null;
                $valor = (float) ($datos['valor'] ?? 0);
                // RN-M04-23, RF-M04-041: mayor a cero y, si es porcentaje, hasta 100 %.
                if (! in_array($tipoValor, ['porcentaje', 'fijo'], true) || $valor <= 0 || ($tipoValor === 'porcentaje' && $valor > 100)) {
                    throw new InvalidArgumentException('El descuento debe ser mayor a cero y, si es porcentaje, no más de 100 %.');
                }
                $regla = [
                    'promocion_id' => null,
                    'nombre' => $tipoValor === 'porcentaje'
                        ? 'Descuento manual '.rtrim(rtrim(number_format($valor, 2, ',', '.'), '0'), ',').' %'
                        : 'Descuento manual',
                    'alcance' => 'total',
                    'tipo_valor' => $tipoValor,
                    'valor' => $valor,
                    'productos' => [],
                    'categorias' => [],
                ];
            }

            $anterior = $pedido->descuento_manual;
            $pedido->descuento_manual = $regla;
            $pedido->descuento_manual_motivo = trim($datos['motivo']);
            $pedido->descuento_manual_por = null; // se completa en cambiarDescuento()
            $pedido->descuento_manual_at = now();

            return [
                'accion' => 'aplicar_descuento',
                'descripcion' => "Descuento aplicado ({$regla['nombre']}): {$pedido->descuento_manual_motivo}",
                'antes' => $anterior ? ['descuento_manual' => $anterior] : ['promociones' => $pedido->promociones_aplicadas],
                'despues' => ['descuento_manual' => $regla, 'motivo' => $pedido->descuento_manual_motivo],
            ];
        });
    }

    /** Quita el descuento manual: vuelven a contar las promociones automáticas del pedido. */
    public function quitarDescuentoManual(Pedido $pedido, User $actor): Pedido
    {
        return $this->cambiarDescuento($pedido, $actor, function (Pedido $pedido) {
            if (! $pedido->descuento_manual) {
                throw new RuntimeException('Este pedido no tiene descuento manual.');
            }
            $anterior = ['descuento_manual' => $pedido->descuento_manual, 'motivo' => $pedido->descuento_manual_motivo];
            $pedido->descuento_manual = null;
            $pedido->descuento_manual_motivo = null;
            $pedido->descuento_manual_por = null;
            $pedido->descuento_manual_at = null;

            return [
                'accion' => 'quitar_descuento',
                'descripcion' => 'Descuento manual retirado',
                'antes' => $anterior,
                'despues' => null,
            ];
        }, quitando: true);
    }

    private function cambiarDescuento(Pedido $pedido, User $actor, callable $cambio, bool $quitando = false): Pedido
    {
        return DB::transaction(function () use ($pedido, $actor, $cambio, $quitando) {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            // Mientras el pedido no se haya cobrado del todo: pendiente, confirmado o en preparación.
            if (! $pedido->puedeModificarse()) {
                throw new RuntimeException($pedido->estado === 'finalizado'
                    ? 'Un pedido finalizado no se puede modificar (RN-PED-11).'
                    : 'Un pedido cancelado no se puede modificar.');
            }

            $totalAnterior = (float) $pedido->total;
            $descuentoAnterior = (float) $pedido->descuento;
            $registro = $cambio($pedido);
            if (! $quitando) {
                $pedido->descuento_manual_por = $actor->id;
            }
            $this->recalcularTotales($pedido);

            if (! $quitando && (float) $pedido->descuento <= 0) {
                throw new RuntimeException('Ese descuento no rebaja nada en este pedido: revisa a qué productos aplica.');
            }
            // Fase 2, tarea 2: el abono del depósito se ajusta si el descuento lo deja sobrando.
            app(AbonoDepositoService::class)->reajustar($pedido, $actor);

            // RF-M06-011: lo pagado nunca puede superar el total.
            $comprometido = $pedido->montoComprometido();
            if ((float) $pedido->total < $comprometido) {
                throw new RuntimeException('Con este descuento el total quedaría por debajo de lo que ya se pagó ($'.number_format($comprometido, 0, ',', '.').'). Anula primero el pago que sobra.');
            }

            $pedido->modificaciones()->create($registro + [
                'user_id' => $actor->id,
                'estado_pedido' => $pedido->estado,
                'total_anterior' => $totalAnterior,
                'total_nuevo' => $pedido->total,
                'afecta_comanda' => false, // la cocina no cambia por un descuento
            ]);

            // RF-M04-039: usuario, motivo y monto.
            app(AuditoriaService::class)->registrar($quitando ? 'quitar_descuento_manual' : 'aplicar_descuento_manual', 'Pedido', $pedido->id, [
                'motivo' => $quitando ? ($registro['antes']['motivo'] ?? null) : $pedido->descuento_manual_motivo,
                'regla' => $quitando ? ($registro['antes']['descuento_manual'] ?? null) : $pedido->descuento_manual,
                'descuento_anterior' => $descuentoAnterior,
                'descuento_nuevo' => (float) $pedido->descuento,
                'total_anterior' => $totalAnterior,
                'total_nuevo' => (float) $pedido->total,
            ], $actor);

            return $pedido->fresh(\App\Http\Controllers\Api\PedidoController::CON_DETALLES);
        });
    }

    /** Foto de una línea para el historial: se lee sola, aunque el catálogo cambie después. */
    private function resumenLinea(PedidoDetalle $detalle): array
    {
        $nombre = $detalle->combo_id
            ? 'Combo '.($detalle->combo?->nombre ?? "#{$detalle->combo_id}")
            : ($detalle->producto?->nombre ?? "Producto #{$detalle->producto_id}");
        if ($detalle->variante) {
            $nombre .= " ({$detalle->variante->nombre})";
        }
        $detalle->loadMissing('extras');

        return [
            'nombre' => $nombre,
            'cantidad' => (int) $detalle->cantidad,
            'precio_unitario' => (float) $detalle->precio_unitario_congelado,
            'extras' => $detalle->extras->pluck('nombre')->values()->all(),
            'observaciones' => $detalle->observaciones ?: null,
            'subtotal' => $detalle->subtotalLinea(),
        ];
    }

    /**
     * HU-M02-020, RF-M02-T-008, RF-M07-028: la historia completa del pedido en orden: creación,
     * cambios de estado, modificaciones de líneas y pagos, cada uno con quién y cuándo.
     */
    public function historial(Pedido $pedido): array
    {
        $pedido->loadMissing(['modificaciones.usuario', 'pagos', 'comprobantes']);
        $nombres = User::whereIn('id', array_filter([
            $pedido->confirmado_por, $pedido->listo_por, $pedido->entregado_por, $pedido->recogido_por,
            $pedido->finalizado_por, $pedido->cancelado_por,
            ...$pedido->pagos->pluck('registrado_por')->all(),
            ...$pedido->pagos->pluck('anulado_por')->all(),
        ]))->pluck('nombre', 'id');
        $quien = fn ($id) => $id ? ($nombres[$id] ?? "Usuario #{$id}") : null;

        $eventos = collect([
            ['tipo' => 'estado', 'fecha' => $pedido->created_at, 'quien' => $pedido->origen === 'mesero' ? 'Mesero' : ($pedido->origen === 'qr' ? 'Cliente (QR de mesa)' : 'Cliente (web)'), 'descripcion' => 'Pedido creado (PENDIENTE)'],
            ['tipo' => 'estado', 'fecha' => $pedido->confirmado_at, 'quien' => $quien($pedido->confirmado_por), 'descripcion' => 'Pedido confirmado'],
            ['tipo' => 'estado', 'fecha' => $pedido->preparando_at, 'quien' => null, 'descripcion' => 'Pasó a preparación'],
            ['tipo' => 'estado', 'fecha' => $pedido->listo_at, 'quien' => $quien($pedido->listo_por), 'descripcion' => 'Listo para recoger'],
            ['tipo' => 'estado', 'fecha' => $pedido->entregado_at, 'quien' => $quien($pedido->entregado_por), 'descripcion' => 'Entrega confirmada'],
            ['tipo' => 'estado', 'fecha' => $pedido->recogido_at, 'quien' => $quien($pedido->recogido_por), 'descripcion' => 'Recogida confirmada'],
            ['tipo' => 'estado', 'fecha' => $pedido->finalizado_at, 'quien' => $quien($pedido->finalizado_por), 'descripcion' => 'Pedido finalizado'],
            ['tipo' => 'estado', 'fecha' => $pedido->cancelado_at, 'quien' => $quien($pedido->cancelado_por), 'descripcion' => "Pedido cancelado: {$pedido->cancelado_motivo}"],
        ])->filter(fn ($e) => $e['fecha'] !== null);

        foreach ($pedido->modificaciones as $m) {
            $eventos->push([
                'tipo' => 'modificacion',
                'fecha' => $m->created_at,
                'quien' => $m->usuario?->nombre,
                'descripcion' => $m->descripcion,
                'total_anterior' => (float) $m->total_anterior,
                'total_nuevo' => (float) $m->total_nuevo,
                'afecta_comanda' => $m->afecta_comanda,
                'antes' => $m->antes,
                'despues' => $m->despues,
            ]);
        }

        // Fase 5, tarea 1: cada comprobante generado y, si se anuló, cuándo y por qué.
        foreach ($pedido->comprobantes as $c) {
            $eventos->push(['tipo' => 'pago', 'fecha' => $c->generado_at, 'quien' => 'Sistema', 'descripcion' => "Comprobante {$c->numero} generado"]);
            if ($c->anulado_at) {
                $eventos->push(['tipo' => 'pago', 'fecha' => $c->anulado_at, 'quien' => 'Sistema', 'descripcion' => "Comprobante {$c->numero} anulado: {$c->anulado_motivo}"]);
            }
        }

        // Fase 6, tarea 2 (RN-PED-06/09): cada comanda mandada a la cocina y cuándo salió impresa.
        $tipos = ['inicial' => 'Comanda', 'actualizacion' => 'Comanda de cambios', 'cancelacion' => 'Comanda de cancelación'];
        foreach ($pedido->comandas()->with('impresaPorUsuario:id,nombre')->get() as $c) {
            $nombre = ($tipos[$c->tipo] ?? 'Comanda')." {$c->pedido_id}-{$c->numero}";
            $eventos->push(['tipo' => 'comanda', 'fecha' => $c->created_at, 'quien' => 'Sistema', 'descripcion' => "{$nombre} enviada a la cocina"]);
            if ($c->impresa_at) {
                $eventos->push(['tipo' => 'comanda', 'fecha' => $c->impresa_at, 'quien' => $c->impresaPorUsuario?->nombre, 'descripcion' => "{$nombre} impresa"]);
            }
        }

        foreach ($pedido->pagos as $p) {
            $monto = '$'.number_format((float) $p->monto, 0, ',', '.');
            // Fase 2, tarea 2: el abono del depósito de una reserva se lee como tal.
            if ($p->esAbonoDeDeposito()) {
                $eventos->push(['tipo' => 'pago', 'fecha' => $p->created_at, 'quien' => $quien($p->registrado_por) ?? 'Sistema', 'descripcion' => "Abono del depósito de la reserva #{$p->reserva_id} por {$monto}"]);
            } else {
                $eventos->push(['tipo' => 'pago', 'fecha' => $p->created_at, 'quien' => $quien($p->registrado_por) ?? 'Cliente (en línea)', 'descripcion' => "Pago {$p->metodo} por {$monto} ({$p->estado})"]);
            }
            if ($p->anulado) {
                $accion = match ($p->reverso_tipo) {
                    'reembolso' => 'Reembolso',
                    'ajuste_abono' => 'Ajuste',
                    default => 'Anulación',
                };
                $eventos->push(['tipo' => 'pago', 'fecha' => $p->anulado_at ?? $p->updated_at, 'quien' => $quien($p->anulado_por) ?? 'Sistema', 'descripcion' => "{$accion} del ".($p->esAbonoDeDeposito() ? 'abono' : 'pago')." de {$monto}: {$p->anulado_motivo}"]);
            }
        }

        return $eventos
            ->map(fn ($e) => ['fecha' => \Illuminate\Support\Carbon::parse($e['fecha'])] + $e)
            // Mismo segundo: la creación va primero y el resto conserva su orden de llegada.
            ->sortBy([fn ($a, $b) => [$a['fecha']->getTimestamp(), $a['descripcion'] === 'Pedido creado (PENDIENTE)' ? 0 : 1]
                <=> [$b['fecha']->getTimestamp(), $b['descripcion'] === 'Pedido creado (PENDIENTE)' ? 0 : 1]])->values()
            ->map(fn ($e) => ['fecha' => $e['fecha']->toIso8601String()] + $e)->all();
    }

    public function confirmar(Pedido $pedido, User $actor): Pedido
    {
        if ($pedido->estado !== 'pendiente') {
            throw new RuntimeException('Solo un pedido pendiente puede confirmarse.');
        }

        // RN-M06-05, RF-M06-007: si el Cliente ya inició un pago en línea para este pedido
        // de domicilio o recogida, tiene que estar aprobado antes de confirmar — no se
        // confirma con un pago en línea todavía pendiente o rechazado. Si no hay pago en
        // línea (se paga contra entrega o al recoger), esta validación no aplica.
        if (($pedido->esDomicilio() || $pedido->esRecogida()) && $this->tienePagoEnLineaSinAprobar($pedido)) {
            throw new RuntimeException('Este pedido tiene un pago en línea sin aprobar; no puede confirmarse todavía (RN-M06-05).');
        }

        $pedido->estado = 'confirmado';
        $pedido->confirmado_por = $actor->id;
        $pedido->confirmado_at = now();
        $pedido->save();

        // Fase 6, tarea 2 (RN-PED-06, RF-M02-L-011, RF-M07-020): confirmar manda la comanda a la
        // cocina. Si algo falla al generarla, el pedido queda confirmado igual (RN-PED-17).
        app(ComandaService::class)->alConfirmar($pedido);

        return $pedido;
    }

    private function tienePagoEnLineaSinAprobar(Pedido $pedido): bool
    {
        return $pedido->pagos()
            ->where('metodo', 'en_linea')
            ->where('anulado', false)
            ->where('estado', '!=', 'aprobado')
            ->exists();
    }

    public function marcarPreparando(Pedido $pedido): Pedido
    {
        if ($pedido->estado !== 'confirmado') {
            throw new RuntimeException('Solo un pedido confirmado puede pasar a preparando.');
        }

        $pedido->estado = 'preparando';
        $pedido->preparando_at = now();
        $pedido->save();

        return $pedido;
    }

    /**
     * Fase 4, tarea 5 — RF-M07-R-012, RN-M07-R-03, HU-M07-012: el Mesero (o el Administrador)
     * marca que una recogida ya está lista y el cliente recibe el aviso por correo (D3: correo en
     * V1, SMS después). No es un estado nuevo: el pedido sigue en PREPARANDO hasta que el cliente
     * lo recoge y se cumple el pago (RN-M07-R-04); solo queda la hora y quién lo marcó. Todas sus
     * líneas quedan preparadas (RN-PED-10: ya no se cambian).
     * Si el pedido no tiene correo (invitado que no lo dejó), no hay aviso: el Mesero llama.
     */
    public function marcarListo(Pedido $pedido, User $actor): Pedido
    {
        if (! $pedido->esRecogida()) {
            throw new RuntimeException('Solo un pedido para recoger se marca como listo para recoger.');
        }
        if ($pedido->estado !== 'preparando') {
            throw new RuntimeException('Solo se marca listo un pedido en preparación.');
        }
        if ($pedido->listo_at !== null || $pedido->recogido_at !== null) {
            throw new RuntimeException('Este pedido ya estaba marcado como listo para recoger.');
        }

        return DB::transaction(function () use ($pedido, $actor) {
            $pedido->detalles()->where('preparado', false)->update(['preparado' => true]);
            $pedido->forceFill(['listo_at' => now(), 'listo_por' => $actor->id])->save();
            app(AvisoPedidoService::class)->programar($pedido->fresh('cliente'), 'listo_para_recoger');
            app(NotificacionService::class)->pedidoListo($pedido, $actor); // M11

            return $pedido->fresh();
        });
    }

    /**
     * El Mesero marca una línea como preparada. No hay panel de cocina (fuera de alcance en
     * M02 §2); M02 §17 deja como pendiente técnico cómo saber que un producto está preparado,
     * y esta marca es la respuesta actual. RN-PED-10 se apoya en ella.
     * CORREGIDO (Fase 0): el comentario citaba "RF-M02-T-017", que no existe en M02.
     */
    public function marcarLineaPreparada(PedidoDetalle $detalle): PedidoDetalle
    {
        $detalle->preparado = true;
        $detalle->save();

        return $detalle;
    }

    /**
     * RN-PED-32, RF-M02-T-016: un pedido pasa de PREPARANDO a FINALIZADO cuando el pago está
     * completo y, en domicilio y recogida, la entrega o la recogida está confirmada
     * (RN-M07-06, RN-M07-R-04). Es la acción manual del Mesero; intentarFinalizar() la usa
     * cuando el último requisito se cumple solo (un cobro, una entrega o un pago en línea).
     *
     * CORREGIDO (Fase 0, 09/10/2026): antes aceptaba pedidos PENDIENTES y CONFIRMADOS, y
     * PagoService lo llamaba apenas el pago quedaba completo. Así un pago en línea aprobado
     * finalizaba un pedido que nadie había confirmado, y un cobro completo finalizaba
     * domicilios sin entregar.
     */
    public function finalizar(Pedido $pedido, ?User $actor): Pedido
    {
        $pedido->refresh();
        $falta = $pedido->faltaParaFinalizar();
        if ($falta !== null) {
            throw new RuntimeException("El pedido no puede finalizarse: {$falta}.");
        }

        $pedido->estado = 'finalizado';
        $pedido->finalizado_por = $actor?->id;
        $pedido->finalizado_at = now();
        $pedido->save();

        // Decisión de implementación (no hay una RN explícita para esto en los documentos):
        // al finalizar un pedido LOCAL se libera la mesa para el siguiente turno.
        if ($pedido->esLocal() && $pedido->mesa_id) {
            $mesa = Mesa::find($pedido->mesa_id);
            if ($mesa && ! $mesa->tienePedidosActivos()) {
                $mesa->liberar();
            }
        }

        return $pedido;
    }

    /**
     * Finaliza el pedido solo si ya cumple todo (RN-PED-32); si no, lo deja como está.
     * Lo llaman el cobro, la aprobación de un pago en línea y la confirmación de entrega o
     * recogida, porque cualquiera de ellos puede ser el último requisito que faltaba.
     */
    public function intentarFinalizar(Pedido $pedido, ?User $actor): Pedido
    {
        $pedido->refresh();

        return $pedido->puedeFinalizarse() ? $this->finalizar($pedido, $actor) : $pedido;
    }

    // RF-M07-025, HU-M07-007: el Mesero confirma que el domicilio llegó. Si ya estaba pagado
    // en línea, con esto finaliza (RF-M07-026); si es contra entrega, ahora se puede cobrar
    // (RF-M06-004, RN-M06-06).
    public function confirmarEntrega(Pedido $pedido, User $actor): Pedido
    {
        if (! $pedido->esDomicilio()) {
            throw new RuntimeException('Solo un pedido a domicilio tiene entrega para confirmar.');
        }
        if ($pedido->estado !== 'preparando') {
            throw new RuntimeException('Solo se confirma la entrega de un domicilio en preparación (ya despachado).');
        }
        if ($pedido->entregado_at !== null) {
            throw new RuntimeException('La entrega de este domicilio ya fue confirmada.');
        }

        $pedido->entregado_por = $actor->id;
        $pedido->entregado_at = now();
        $pedido->save();

        return $this->intentarFinalizar($pedido, $actor);
    }

    // RF-M07-R-013, HU-M07-013: el Mesero confirma que el Cliente recogió el pedido en el local.
    // Con el pago completo, finaliza (RF-M07-R-014); el cobro puede ir antes o después.
    public function confirmarRecogida(Pedido $pedido, User $actor): Pedido
    {
        if (! $pedido->esRecogida()) {
            throw new RuntimeException('Solo un pedido para recoger tiene recogida para confirmar.');
        }
        if ($pedido->estado !== 'preparando') {
            throw new RuntimeException('Solo se confirma la recogida de un pedido en preparación.');
        }
        if ($pedido->recogido_at !== null) {
            throw new RuntimeException('La recogida de este pedido ya fue confirmada.');
        }

        $pedido->recogido_por = $actor->id;
        $pedido->recogido_at = now();
        $pedido->save();

        return $this->intentarFinalizar($pedido, $actor);
    }

    /**
     * Fase 1, tarea 6 (11/10/2026): RF-M02-L-024, RF-M07-016/017, RF-M07-R-009/010, RN-M07-04/05.
     * - El Mesero cancela un pedido PENDIENTE. Uno ya CONFIRMADO o en PREPARANDO solo con la
     *   autorización de un Administrador (su contraseña), porque la cocina ya lo tiene; queda en
     *   la auditoría quién autorizó. El Administrador cancela cualquier pedido activo.
     * - El Cliente (con cuenta o, por su enlace, el invitado) cancela su domicilio o su recogida
     *   mientras esté PENDIENTE (eso lo filtra PedidoPolicy::cancelar o la ruta del token).
     * $actor es null cuando cancela el invitado por su enlace.
     */
    public function cancelar(Pedido $pedido, ?User $actor, string $motivo, ?string $passwordAdministrador = null): Pedido
    {
        if (! $pedido->puedeModificarse()) {
            throw new RuntimeException('Un pedido ya finalizado o cancelado no puede cancelarse.'); // RN-PED-10
        }

        $autorizadoPor = null;
        if ($actor?->esMesero() && $pedido->estado !== 'pendiente') {
            $autorizadoPor = $this->administradorQueAutoriza($passwordAdministrador);
            if (! $autorizadoPor) {
                throw new RuntimeException('El pedido ya está confirmado: para cancelarlo hace falta la contraseña de un Administrador (RN-M07-05).');
            }
        }

        $estadoAnterior = $pedido->estado;
        $pedido->estado = 'cancelado';
        $pedido->cancelado_por = $actor?->id;
        $pedido->cancelado_motivo = $motivo;
        $pedido->cancelado_at = now();
        $pedido->save();

        // Fase 2, tarea 2: el abono del depósito de la reserva vuelve a la reserva y pasa a los
        // demás pedidos de la visita.
        app(AbonoDepositoService::class)->liberarDePedidoCancelado($pedido, $actor);
        // Fase 6, tarea 2: si la cocina ya tenía el pedido, le llega una comanda de cancelación.
        app(ComandaService::class)->alCancelar($pedido, $motivo);
        // Fase 6, tarea 1: si ya se había pagado por la pasarela, queda marcado para reembolsarlo.
        app(PasarelaPagoService::class)->avisarPedidoCancelado($pedido);

        // RNF-M02-003/012: cancelar un pedido queda auditado.
        app(AuditoriaService::class)->registrar('cancelar_pedido', 'Pedido', $pedido->id, [
            'motivo' => $motivo,
            'estado_anterior' => $estadoAnterior,
            'autorizado_por' => $autorizadoPor?->id,
            'por_el_cliente' => $actor === null || $actor->esCliente(),
        ], $actor);
        app(NotificacionService::class)->pedidoCanceladoPorCliente($pedido, $actor); // M11

        if ($pedido->esLocal() && $pedido->mesa_id) {
            $mesa = Mesa::find($pedido->mesa_id);
            if ($mesa && ! $mesa->tienePedidosActivos()) {
                $mesa->liberar();
            }
        }

        return $pedido;
    }

    // El Administrador activo cuya contraseña se escribió, o null. Se revisan todos porque el
    // Mesero no elige quién autoriza: el que esté en el local escribe su clave en el teléfono.
    private function administradorQueAutoriza(?string $password): ?User
    {
        if ($password === null || $password === '') {
            return null;
        }

        return User::whereIn('role', User::ROLES_ADMINISTRACION)->where('activo', true)->get()
            ->first(fn (User $admin) => \Illuminate\Support\Facades\Hash::check($password, $admin->password));
    }
}
