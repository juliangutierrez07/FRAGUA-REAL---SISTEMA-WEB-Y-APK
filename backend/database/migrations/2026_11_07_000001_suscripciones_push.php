<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M11 — Avisos push del personal (28/10/2026, RF-M11-013, RN-M11-09): cada teléfono, tableta o computador
// donde alguien del personal activó los avisos. Va unida a la sesión con que se activó (`token_id`): al
// cerrar esa sesión, vencer o revocarse, la suscripción se borra sola y el equipo deja de recibir avisos de
// esa cuenta. `endpoint` es la dirección que da el servicio push del navegador (se busca por su huella).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_suscripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 120);
            $table->string('auth', 40);
            $table->string('navegador', 120)->nullable();
            $table->timestamp('ultimo_envio_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
            $table->index('token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_suscripciones');
    }
};
