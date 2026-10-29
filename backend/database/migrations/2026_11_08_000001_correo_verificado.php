<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Seguridad (28/10/2026, M01: RF-M01-037, RN-USR-28): cuándo confirmó el Cliente que el correo de su cuenta es
// suyo (con el código que le llega). Antes cualquiera podía registrarse con el correo de otra persona y ver sus
// reservas en «Mis pedidos y reservas», que se buscan por ese correo. Nulo = sin confirmar (incluye a los
// clientes de antes, que lo confirman desde su Perfil).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('correo_verificado_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('correo_verificado_at');
        });
    }
};
