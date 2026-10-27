<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Mejoras de operación (26/10/2026; RF-M02-L-033, RF-M04-056, RF-M07-033 y 034):
//  - pedidos.cuenta: cuentas separadas en una misma mesa. Los pedidos locales de la visita se agrupan
//    por número de cuenta (1, 2, 3…); cada cuenta se cobra aparte (M02, M06).
//  - pedidos.ubicacion_lat/lng: la ubicación actual que comparte el Cliente al pedir a domicilio (M07).
//  - pedidos.paga_con: el billete con el que paga contra entrega, para que el domiciliario lleve el
//    cambio (M07). Vacío: paga con el valor exacto o no lo dijo.
//  - categorias.permite_quitar_ingredientes: si el menú le pregunta al Cliente qué ingrediente quitar.
//    Las bebidas no llevan ingredientes (M04).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->unsignedSmallInteger('cuenta')->nullable()->after('mesa_id');
            $table->decimal('ubicacion_lat', 10, 7)->nullable()->after('referencia');
            $table->decimal('ubicacion_lng', 10, 7)->nullable()->after('ubicacion_lat');
            $table->decimal('paga_con', 12, 2)->nullable()->after('pasarela_preferida');
        });
        DB::table('pedidos')->where('tipo', 'local')->update(['cuenta' => 1]);

        Schema::table('categorias', function (Blueprint $table) {
            $table->boolean('permite_quitar_ingredientes')->default(true)->after('activo');
        });
        foreach (DB::table('categorias')->get(['id', 'nombre']) as $categoria) {
            if (preg_match('/bebida|gaseosa|jugo|limonada|refresco|cerveza|licor|caf[eé]|t[eé]\b|agua/iu', $categoria->nombre)) {
                DB::table('categorias')->where('id', $categoria->id)->update(['permite_quitar_ingredientes' => false]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('categorias', fn (Blueprint $table) => $table->dropColumn('permite_quitar_ingredientes'));
        Schema::table('pedidos', fn (Blueprint $table) => $table->dropColumn(['cuenta', 'ubicacion_lat', 'ubicacion_lng', 'paga_con']));
    }
};
