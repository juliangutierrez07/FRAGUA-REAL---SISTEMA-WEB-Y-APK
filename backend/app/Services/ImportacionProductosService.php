<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\User;
use App\Models\Variante;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Mejora (16/10/2026): importación masiva de productos desde Excel (o CSV) en Catálogo.
 *
 * Tres pasos, sin guardar nada hasta el último:
 *  1. plantilla(): el archivo con las columnas, una fila de ejemplo y la hoja «Instrucciones».
 *  2. analizar(): lee el archivo y dice, fila por fila, qué haría (crear, actualizar o error), con los
 *     mismos controles que el formulario de Catálogo: nombre único (RN-M04-14), precio mayor a cero
 *     (RN-M04-10), tipo de venta válido (RF-M04-042) y presentaciones con su propio precio
 *     (RF-M04-008).
 *  3. importar(): vuelve a analizar el mismo archivo y, solo si no hay errores, crea o actualiza todo
 *     en una sola transacción; si algo falla, no queda nada a medias. Cada producto, presentación y
 *     categoría queda en la auditoría como si se hubiera hecho a mano (RF-M04-022).
 *
 * Reglas:
 *  - Un producto se identifica por su nombre (sin importar mayúsculas). Si ya existe y se pidió
 *    actualizar, cambian los datos que trae la fila; las celdas vacías no borran nada.
 *  - Presentaciones: «Personal:18000 | Familiar:32000». Al actualizar, las que vienen se crean o
 *    cambian de precio; las que no vienen se dejan como están.
 *  - Una categoría que no existe se crea (la vista previa lo avisa).
 *  - Fotos, extras y combos no van en el archivo: se manejan en Catálogo.
 */
class ImportacionProductosService
{
    public const MAX_FILAS = 500;

    private const COLUMNAS = ['Categoría', 'Nombre', 'Descripción', 'Precio base', 'Tipo de venta', 'Presentaciones', 'Disponible'];

    private const TIPOS_VENTA = ['alimento' => 'alimento', 'bebida' => 'bebida', 'otro' => 'otro'];

    public function __construct(private readonly CatalogoService $catalogo, private readonly AuditoriaService $auditoria)
    {
    }

    /** El archivo de ejemplo que se descarga desde Catálogo. */
    public function plantilla(): string
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Productos');
        $hoja->fromArray(self::COLUMNAS, null, 'A1');
        $hoja->fromArray(['Hamburguesas', 'Hamburguesa de ejemplo', 'Carne de res, queso y salsa de la casa.', 18000, 'Alimento', 'Personal:18000 | Familiar:32000', 'Sí'], null, 'A2');
        $hoja->fromArray(['Bebidas', 'Limonada de ejemplo', 'Natural o de panela.', 6000, 'Bebida', '', 'Sí'], null, 'A3');

        $encabezado = $hoja->getStyle('A1:G1');
        $encabezado->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $encabezado->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('171510');
        foreach (range('A', 'G') as $col) {
            $hoja->getColumnDimension($col)->setWidth($col === 'C' || $col === 'F' ? 40 : 20);
        }
        $hoja->freezePane('A2');

        // Listas desplegables para no escribir mal el tipo de venta ni «Disponible».
        foreach (['E' => '"Alimento,Bebida,Otro"', 'G' => '"Sí,No"'] as $col => $opciones) {
            $validacion = $hoja->getCell("{$col}2")->getDataValidation();
            $validacion->setType(DataValidation::TYPE_LIST)->setShowDropDown(true)->setAllowBlank(true)->setFormula1($opciones);
            $hoja->setDataValidation("{$col}2:{$col}".(self::MAX_FILAS + 1), $validacion);
        }

        $ayuda = $libro->createSheet()->setTitle('Instrucciones');
        $ayuda->fromArray([
            ['Cómo llenar la hoja «Productos»'],
            ['Categoría', 'Obligatoria. Si no existe, se crea.'],
            ['Nombre', 'Obligatorio y único en todo el menú. Si ya existe, el producto se actualiza (si eliges «Actualizar los que ya existen»).'],
            ['Descripción', 'Opcional.'],
            ['Precio base', 'Obligatorio para productos nuevos. Número mayor a cero, sin puntos ni signo $ (18000).'],
            ['Tipo de venta', 'Alimento, Bebida u Otro (para el cierre de caja). Obligatorio para productos nuevos.'],
            ['Presentaciones', 'Opcional. Nombre:precio separados por |, por ejemplo Personal:18000 | Familiar:32000.'],
            ['Disponible', 'Sí o No. Vacío: Sí para productos nuevos y sin cambio para los existentes.'],
            [''],
            ['Hasta '.self::MAX_FILAS.' productos por archivo. Las celdas vacías de un producto que ya existe no borran nada.'],
            ['Las fotos, los extras y los combos se manejan en Catálogo.'],
        ], null, 'A1');
        $ayuda->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ayuda->getColumnDimension('A')->setWidth(18);
        $ayuda->getColumnDimension('B')->setWidth(100);
        $libro->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($libro))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * Lo que haría la importación, sin guardar nada.
     *
     * @return array{filas: list<array<string, mixed>>, resumen: array<string, int>, categorias_nuevas: list<string>}
     */
    public function analizar(UploadedFile $archivo, bool $actualizar): array
    {
        $crudas = $this->leer($archivo);
        if (! $crudas) {
            throw new RuntimeException('El archivo no tiene productos. Llena la hoja desde la fila 2, debajo de los títulos.');
        }
        if (count($crudas) > self::MAX_FILAS) {
            throw new RuntimeException('El archivo tiene '.count($crudas).' productos; el máximo es '.self::MAX_FILAS.' por archivo.');
        }

        // 27/10/2026: lo eliminado no cuenta; su nombre está libre y se crea de nuevo.
        $existentes = Producto::with(['variantes', 'categoria'])->whereNull('eliminado_at')->get()->keyBy(fn (Producto $p) => $this->clave($p->nombre));
        $categorias = Categoria::whereNull('eliminado_at')->get()->keyBy(fn (Categoria $c) => $this->clave($c->nombre));
        $vistos = [];
        $categoriasNuevas = [];
        $filas = [];

        foreach ($crudas as $numero => $c) {
            $errores = [];
            $nombre = $this->texto($c['nombre'] ?? '');
            $claveNombre = $this->clave($nombre);
            $existente = $nombre !== '' ? $existentes->get($claveNombre) : null;

            if ($nombre === '') {
                $errores[] = 'Falta el nombre.';
            } elseif (mb_strlen($nombre) > 255) {
                $errores[] = 'El nombre es muy largo (máximo 255 caracteres).';
            } elseif (isset($vistos[$claveNombre])) {
                $errores[] = "El nombre se repite en la fila {$vistos[$claveNombre]}.";
            } elseif ($existente && ! $actualizar) {
                $errores[] = 'Ya existe un producto con ese nombre (activa «Actualizar los que ya existen» para cambiarlo).';
            }
            if ($nombre !== '') {
                $vistos[$claveNombre] ??= $numero;
            }

            $categoria = $this->texto($c['categoria'] ?? '');
            if ($categoria === '' && ! $existente) {
                $errores[] = 'Falta la categoría.';
            } elseif ($categoria !== '' && ! $categorias->has($this->clave($categoria)) && ! in_array($this->clave($categoria), array_map(fn ($n) => $this->clave($n), $categoriasNuevas), true)) {
                $categoriasNuevas[] = $categoria;
            }

            $precio = $this->numero($c['precio'] ?? '');
            if ($precio === false) {
                $errores[] = 'El precio base no es un número.';
            } elseif ($precio === null && ! $existente) {
                $errores[] = 'Falta el precio base.';
            } elseif ($precio !== null && $precio <= 0) {
                $errores[] = 'El precio base debe ser mayor a cero.';
            }

            $tipoTexto = $this->clave($c['tipo'] ?? '');
            $tipo = self::TIPOS_VENTA[$tipoTexto] ?? null;
            if ($tipoTexto === '' && ! $existente) {
                $errores[] = 'Falta el tipo de venta (Alimento, Bebida u Otro).';
            } elseif ($tipoTexto !== '' && ! $tipo) {
                $errores[] = 'El tipo de venta debe ser Alimento, Bebida u Otro.';
            }

            [$presentaciones, $erroresPresentaciones] = $this->presentaciones((string) ($c['presentaciones'] ?? ''));
            array_push($errores, ...$erroresPresentaciones);

            $disponibleTexto = $this->clave($c['disponible'] ?? '');
            $disponible = match ($disponibleTexto) {
                '' => null,
                'si', 'sí', 'x', '1', 'true', 'verdadero' => true,
                'no', '0', 'false', 'falso' => false,
                default => 'invalido',
            };
            if ($disponible === 'invalido') {
                $errores[] = '«Disponible» debe ser Sí o No.';
                $disponible = null;
            }

            $descripcion = $this->texto($c['descripcion'] ?? '');

            $cambios = [];
            if ($existente && ! $errores) {
                if ($categoria !== '' && $this->clave($existente->categoria?->nombre ?? '') !== $this->clave($categoria)) {
                    $cambios[] = "categoría a {$categoria}";
                }
                if ($descripcion !== '' && $descripcion !== (string) $existente->descripcion) {
                    $cambios[] = 'descripción';
                }
                if ($precio !== null && (float) $existente->precio_base !== (float) $precio) {
                    $cambios[] = 'precio de $'.number_format((float) $existente->precio_base, 0, ',', '.').' a $'.number_format($precio, 0, ',', '.');
                }
                if ($tipo && $tipo !== $existente->tipo_venta) {
                    $cambios[] = 'tipo de venta';
                }
                if ($disponible !== null && $disponible !== (bool) $existente->disponible) {
                    $cambios[] = $disponible ? 'disponible' : 'agotado';
                }
                foreach ($presentaciones as $p) {
                    $actual = $existente->variantes->first(fn (Variante $v) => $this->clave($v->nombre) === $this->clave($p['nombre']));
                    if (! $actual) {
                        $cambios[] = "nueva presentación {$p['nombre']}";
                    } elseif ((float) $actual->precio !== $p['precio']) {
                        $cambios[] = "{$p['nombre']} a $".number_format($p['precio'], 0, ',', '.');
                    }
                }
            }

            $filas[] = [
                'fila' => $numero,
                'nombre' => $nombre,
                'categoria' => $categoria ?: ($existente?->categoria?->nombre ?? ''),
                'precio_base' => $precio === false ? null : $precio,
                'tipo_venta' => $tipo,
                'presentaciones' => $presentaciones,
                'disponible' => $disponible,
                'descripcion' => $descripcion,
                'accion' => $errores ? 'error' : ($existente ? ($cambios ? 'actualizar' : 'sin_cambios') : 'crear'),
                'cambios' => $cambios,
                'errores' => $errores,
                'producto_id' => $existente?->id,
            ];
        }

        $cuenta = fn (string $accion) => count(array_filter($filas, fn ($f) => $f['accion'] === $accion));

        return [
            'filas' => $filas,
            'resumen' => [
                'total' => count($filas),
                'crear' => $cuenta('crear'),
                'actualizar' => $cuenta('actualizar'),
                'sin_cambios' => $cuenta('sin_cambios'),
                'errores' => $cuenta('error'),
            ],
            'categorias_nuevas' => $categoriasNuevas,
        ];
    }

    /**
     * Crea y actualiza todo, o nada si hay algún error.
     *
     * @return array{resumen: array<string, int>, categorias_nuevas: list<string>}
     */
    public function importar(UploadedFile $archivo, bool $actualizar, User $actor): array
    {
        $analisis = $this->analizar($archivo, $actualizar);
        if ($analisis['resumen']['errores'] > 0) {
            throw new RuntimeException('El archivo tiene '.$analisis['resumen']['errores'].' fila(s) con errores. Corrígelas y vuelve a intentarlo; no se guardó nada.');
        }

        DB::transaction(function () use ($analisis, $actor) {
            $categorias = Categoria::whereNull('eliminado_at')->get()->keyBy(fn (Categoria $c) => $this->clave($c->nombre));
            foreach ($analisis['categorias_nuevas'] as $nombre) {
                $categoria = Categoria::create(['nombre' => $nombre, 'activo' => true]);
                $this->catalogo->auditar('crear_categoria', $categoria, null, $actor, ['origen' => 'importacion']);
                $categorias->put($this->clave($nombre), $categoria);
            }

            foreach ($analisis['filas'] as $fila) {
                $categoriaId = $fila['categoria'] !== '' ? $categorias->get($this->clave($fila['categoria']))?->id : null;

                if ($fila['accion'] === 'crear') {
                    $producto = Producto::create([
                        'categoria_id' => $categoriaId,
                        'nombre' => $fila['nombre'],
                        'descripcion' => $fila['descripcion'] ?: null,
                        'precio_base' => $fila['precio_base'],
                        'tipo_venta' => $fila['tipo_venta'],
                        'disponible' => $fila['disponible'] ?? true,
                        'activo' => true,
                    ]);
                    $this->catalogo->auditar('crear_producto', $producto, null, $actor, ['origen' => 'importacion']);
                } elseif ($fila['accion'] === 'actualizar') {
                    $producto = Producto::findOrFail($fila['producto_id']);
                    $antes = $producto->getAttributes();
                    $producto->fill(array_filter([
                        'categoria_id' => $categoriaId,
                        'descripcion' => $fila['descripcion'] ?: null,
                        'precio_base' => $fila['precio_base'],
                        'tipo_venta' => $fila['tipo_venta'],
                        'disponible' => $fila['disponible'],
                    ], fn ($v) => $v !== null));
                    if ($producto->isDirty()) {
                        $producto->save();
                        $this->catalogo->auditar('editar_producto', $producto, $antes, $actor, ['origen' => 'importacion']);
                    }
                } else {
                    continue;
                }

                foreach ($fila['presentaciones'] as $p) {
                    $variante = $producto->variantes()->get()->first(fn (Variante $v) => $this->clave($v->nombre) === $this->clave($p['nombre']));
                    if (! $variante) {
                        $variante = Variante::create(['producto_id' => $producto->id, 'nombre' => $p['nombre'], 'precio' => $p['precio'], 'activa' => true]);
                        $this->catalogo->auditar('crear_variante', $variante, null, $actor, ['origen' => 'importacion']);
                    } elseif ((float) $variante->precio !== $p['precio']) {
                        $antes = $variante->getAttributes();
                        $variante->update(['precio' => $p['precio']]);
                        $this->catalogo->auditar('editar_variante', $variante, $antes, $actor, ['origen' => 'importacion']);
                    }
                }
            }

            $this->auditoria->registrar('importar_productos', 'Producto', null, [
                'archivo_filas' => $analisis['resumen']['total'],
                'creados' => $analisis['resumen']['crear'],
                'actualizados' => $analisis['resumen']['actualizar'],
                'categorias_nuevas' => $analisis['categorias_nuevas'],
            ], $actor);
        });

        return ['resumen' => $analisis['resumen'], 'categorias_nuevas' => $analisis['categorias_nuevas']];
    }

    /**
     * Las filas del archivo (desde la 2), por el título de cada columna; las filas vacías se saltan.
     *
     * @return array<int, array<string, mixed>> número de fila => valores
     */
    private function leer(UploadedFile $archivo): array
    {
        try {
            // El archivo subido no conserva la extensión en disco: el lector se elige por la del nombre.
            $lector = IOFactory::createReader(match (strtolower($archivo->getClientOriginalExtension())) {
                'csv', 'txt' => 'Csv',
                'xls' => 'Xls',
                default => 'Xlsx',
            });
            if ($lector instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
                // Excel en español guarda los CSV con «;» y a veces en Windows-1252: se detectan solos.
                $lector->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);
                $lector->setDelimiter(null);
                // Todo como texto: si no, «18.000» llega convertido al número 18 y se pierde el precio.
                $lector->setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder());
            }
            $lector->setReadDataOnly(true);
            $hoja = $lector->load($archivo->getRealPath())->getSheet(0);
        } catch (\Throwable) {
            throw new RuntimeException('No pudimos leer el archivo. Usa la plantilla en Excel (.xlsx) o un CSV.');
        }

        $datos = $hoja->toArray(null, true, false, false);
        $titulos = array_map(fn ($t) => $this->clave((string) $t), $datos[0] ?? []);
        $mapa = [
            'categoria' => ['categoria'],
            'nombre' => ['nombre', 'producto'],
            'descripcion' => ['descripcion'],
            'precio' => ['precio base', 'precio'],
            'tipo' => ['tipo de venta', 'tipo'],
            'presentaciones' => ['presentaciones', 'variantes', 'tamanos'],
            'disponible' => ['disponible'],
        ];
        $indices = [];
        foreach ($mapa as $campo => $alias) {
            foreach ($titulos as $i => $titulo) {
                if (in_array($titulo, $alias, true)) {
                    $indices[$campo] = $i;
                    break;
                }
            }
        }
        if (! isset($indices['nombre'])) {
            throw new RuntimeException('El archivo no tiene la columna «Nombre». Usa la plantilla de Catálogo.');
        }

        $filas = [];
        foreach (array_slice($datos, 1, null, true) as $i => $valores) {
            $fila = [];
            foreach ($indices as $campo => $col) {
                $fila[$campo] = $valores[$col] ?? null;
            }
            if (implode('', array_map(fn ($v) => trim((string) $v), $fila)) === '') {
                continue;
            }
            $filas[$i + 1] = $fila;
        }

        return $filas;
    }

    /** @return array{0: list<array{nombre: string, precio: float}>, 1: list<string>} */
    private function presentaciones(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [[], []];
        }
        $lista = [];
        $errores = [];
        $nombres = [];
        foreach (preg_split('/[|;\n]+/', $texto) as $parte) {
            $parte = trim($parte);
            if ($parte === '') {
                continue;
            }
            if (! preg_match('/^(.+?)\s*[:=]\s*([\d.,$\s]+)$/u', $parte, $m)) {
                $errores[] = "Presentación «{$parte}»: escríbela como Nombre:precio (Familiar:32000).";
                continue;
            }
            $nombre = $this->texto($m[1]);
            $precio = $this->numero($m[2]);
            if ($precio === false || $precio === null || $precio <= 0) {
                $errores[] = "Presentación «{$nombre}»: el precio debe ser mayor a cero.";
                continue;
            }
            if (isset($nombres[$this->clave($nombre)])) {
                $errores[] = "La presentación «{$nombre}» está repetida.";
                continue;
            }
            $nombres[$this->clave($nombre)] = true;
            $lista[] = ['nombre' => $nombre, 'precio' => $precio];
        }

        return [$lista, $errores];
    }

    private function texto(mixed $valor): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '');
    }

    /** Compara sin mayúsculas, tildes ni espacios de más. */
    private function clave(mixed $valor): string
    {
        return Str::lower(Str::ascii($this->texto($valor)));
    }

    /** Número desde «18000», «18.000», «$ 18.000» o 18000.0; null si está vacío; false si no es número. */
    private function numero(mixed $valor): float|false|null
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        $texto = str_replace(['$', ' ', "\u{00A0}"], '', trim((string) $valor));
        if ($texto === '') {
            return null;
        }
        // En Colombia el punto separa miles: «18.000» es dieciocho mil. Una coma decimal se acepta.
        $texto = preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $texto) ? str_replace('.', '', $texto) : $texto;
        $texto = str_replace(',', '.', $texto);

        return is_numeric($texto) ? (float) $texto : false;
    }
}
