{{-- Diseño de tickets (29/10/2026): estilos del comprobante. $t es el tamaño base de la letra en px (el ticket
     térmico usa 8.6 o 10.2 según «letra grande»; el PDF digital, un poco más). Letras de dompdf (DejaVu): Sans
     en negrita para la marca y los números grandes, Sans Mono para el resto, así las cifras quedan alineadas. --}}
<style>
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: {{ $t }}px; color: #111; line-height: 1.4; }
    .centro { text-align: center; }
    .derecha { text-align: right; }
    .suave { color: #555; }
    .pequeno { font-size: {{ round($t * 0.88, 1) }}px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; padding: 0; }
    hr { border: 0; border-top: 1px dashed #111; margin: {{ round($t * 0.8, 1) }}px 0; }
    hr.doble { border-top: 3px double #111; }
    .logo { max-height: {{ round($t * 5.5) }}px; max-width: {{ round($t * 14) }}px; margin-bottom: 3px; }
    .marca { font-family: 'DejaVu Sans', sans-serif; font-weight: bold; font-size: {{ round($t * 2.3, 1) }}px; line-height: 1; letter-spacing: -0.5px; text-transform: uppercase; }
    .marca-sub { font-family: 'DejaVu Sans', sans-serif; font-weight: bold; font-size: {{ round($t * 0.9, 1) }}px; letter-spacing: {{ round($t * 0.55, 1) }}px; text-transform: uppercase; margin-top: 2px; }
    .eslogan { font-family: 'DejaVu Serif', serif; font-style: italic; font-size: {{ round($t * 1.05, 1) }}px; margin-top: 3px; }
    .nit { font-weight: bold; letter-spacing: 1px; margin-top: 4px; }
    .etiqueta { font-weight: bold; letter-spacing: 1.5px; }
    .numero { margin: 4px 0 3px; }
    .numero span { background: #111; color: #fff; font-family: 'DejaVu Sans', sans-serif; font-weight: bold; font-size: {{ round($t * 1.5, 1) }}px; letter-spacing: 1px; padding: 2px 9px; }
    .aviso { font-family: 'DejaVu Sans', sans-serif; font-weight: bold; font-size: {{ round($t * 1.4, 1) }}px; margin-top: 4px; }
    .aviso-borde { border: 1.5px solid #111; padding: 2px 4px; font-weight: bold; margin-top: 4px; text-align: center; }
    .datos td { padding: 1px 0; }
    .datos td.derecha { padding-left: 8px; }
    .canal { border: 1.5px solid #111; padding: 0 4px; font-weight: bold; font-size: {{ round($t * 0.85, 1) }}px; letter-spacing: 1px; text-transform: uppercase; }
    .productos th { font-size: {{ round($t * 0.85, 1) }}px; font-weight: bold; letter-spacing: 1px; text-align: left; border-bottom: 1.5px solid #111; padding-bottom: 3px; }
    .productos th.derecha { text-align: right; }
    .productos td { padding-top: 5px; }
    .productos .cant { width: 13%; }
    .productos .valor { white-space: nowrap; padding-left: 6px; }
    .sumas td { padding: 1px 0; }
    .total { margin-top: 5px; border-top: 1.5px solid #111; }
    .total td { padding-top: 5px; vertical-align: bottom; }
    .total-etiqueta { font-weight: bold; letter-spacing: 2px; }
    .total-valor { font-family: 'DejaVu Sans', sans-serif; font-weight: bold; font-size: {{ round($t * 2, 1) }}px; line-height: 1; }
    .seccion { font-weight: bold; letter-spacing: 1.5px; margin-bottom: 2px; }
    .pagos td { padding-top: 1px; }
    .gracias { font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; margin-top: 2px; }
    .estrellas { letter-spacing: 3px; color: #555; margin-top: 6px; font-size: {{ round($t * 0.8, 1) }}px; }
</style>
