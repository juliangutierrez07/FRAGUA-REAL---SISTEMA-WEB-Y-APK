<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fidelización (27/10/2026, M01: RF-M01-035, RN-USR-26): el Cliente se registra con su número de documento
// (cédula, cédula de extranjería o pasaporte) y el Mesero lo busca al cobrar por documento, correo o
// celular para dejarle el pedido en su historial. Por eso el documento y el celular no se repiten entre
// clientes vigentes (el personal no cuenta: un Mesero puede tener el mismo celular que su cuenta de
// cliente). La columna admite nulo porque los clientes de antes no la tienen.
return new class extends Migration
{
    public function up(): void
    {
        $repetidos = DB::table('users')->where('role', 'cliente')->whereNull('eliminado_at')->whereNotNull('telefono')
            ->select('telefono')->groupBy('telefono')->havingRaw('count(*) > 1')->pluck('telefono');
        if ($repetidos->isNotEmpty()) {
            // No se borra ni se cambia nada: el Administrador corrige esos clientes en Usuarios y se vuelve a migrar.
            throw new RuntimeException('Hay clientes con el mismo celular: '.$repetidos->implode(', ')
                .'. Corrígelos en Usuarios (cada celular en una sola cuenta) y vuelve a correr la migración.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('documento', 20)->nullable()->after('telefono');
        });
        DB::statement("CREATE UNIQUE INDEX users_documento_cliente_unique ON users (documento) WHERE role = 'cliente' AND eliminado_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX users_telefono_cliente_unique ON users (telefono) WHERE role = 'cliente' AND eliminado_at IS NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_telefono_cliente_unique');
        DB::statement('DROP INDEX users_documento_cliente_unique');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('documento');
        });
    }
};
