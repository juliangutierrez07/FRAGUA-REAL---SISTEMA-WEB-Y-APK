<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M12 — Impresoras (24/10/2026). Cada equipo del local que imprime (la tablet de la cocina, el PC de
// la caja) se registra con un nombre. La clave se guarda en ese navegador y lo identifica; con ella
// manda su señal cada minuto, recibe sus ajustes (qué imprime y si abre solas las comandas) y deja el
// resultado de su última impresión de prueba. El código de vinculación sirve para registrar un equipo
// donde está abierta la sesión de un Mesero (lo genera el Administrador y vale 30 minutos).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipos_impresion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 60);
            $table->string('clave', 64)->nullable()->unique();
            $table->string('codigo_vinculacion', 6)->nullable()->unique();
            $table->timestamp('codigo_vence_at')->nullable();
            $table->boolean('imprime_comandas')->default(true);
            $table->boolean('imprime_comprobantes')->default(true);
            $table->boolean('al_confirmar')->default(true);
            $table->boolean('automatico')->default(false);
            $table->timestamp('ultima_senal_at')->nullable();
            $table->foreignId('ultima_senal_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ultima_prueba_at')->nullable();
            $table->string('ultima_prueba_resultado', 10)->nullable(); // ok | fallo | cortado
            $table->foreignId('ultima_prueba_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipos_impresion');
    }
};
