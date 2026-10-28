/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js,ts,jsx,tsx}'],
  theme: {
    extend: {
      // Paleta y tipografía tomadas del Figma "Fragua Real Diseños V1"
      // (Home V2 / Menu V2, fileKey 19fUr3yzn4pua35EO84Fua) — dorado de marca,
      // negro cálido para textos fuertes y fondo crema para secciones claras.
      colors: {
        brand: {
          50: '#fdf8ee',
          100: '#fbf0d9',
          200: '#f5dfad',
          300: '#eeca78',
          400: '#e3b656',
          500: '#d6a43a', // dorado de marca (botones primarios, acentos)
          600: '#a47110',
          700: '#8f6310',
          800: '#725317',
          900: '#5c4212',
        },
        ink: {
          DEFAULT: '#171510', // negro cálido — texto fuerte, header oscuro, footer
          soft: '#15130f',
          700: '#2a261d', // superficies elevadas sobre el negro (hover, tarjeta de usuario)
          800: '#1f1c15',
          900: '#110f0b', // fondo del menú lateral
        },
        cream: '#f8f5ee', // fondo de sección clara
        // Neutros CÁLIDOS (reemplazan el gris azulado de Tailwind): todo "gray-*" del sistema
        // hereda el mismo tono crema/tierra de la marca — bordes (#e9e3d8), textos secundarios
        // (#a39c8f / #6c655c) y fondos suaves ya coinciden con los que usa el sitio del Cliente.
        gray: {
          50: '#faf8f4',
          100: '#f4f0e8',
          200: '#e9e3d8',
          300: '#d8d0c1',
          400: '#a39c8f',
          500: '#7b7468',
          600: '#625e57',
          700: '#4a463f',
          800: '#2d2a24',
          900: '#171510',
        },
      },
      fontFamily: {
        display: ['"Playfair Display"', 'serif'], // títulos
        sans: ['"DM Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'], // cuerpo/UI
      },
      boxShadow: {
        // Sombras cálidas (tinte del negro de marca, no negro puro): tarjetas de lista,
        // elementos flotantes (modales, menús) y barra inferior móvil.
        card: '0 1px 2px rgba(23,21,16,0.04), 0 6px 18px -8px rgba(23,21,16,0.10)',
        'card-hover': '0 2px 4px rgba(23,21,16,0.05), 0 14px 28px -12px rgba(23,21,16,0.18)',
        pop: '0 24px 60px -12px rgba(23,21,16,0.35)',
        nav: '0 -8px 30px -12px rgba(23,21,16,0.22)',
        gold: '0 8px 20px rgba(214,164,58,0.35)',
      },
      spacing: {
        // Altura de la barra inferior móvil del Mesero (incluye el área segura del iPhone), más un
        // respiro (28/10/2026): la última tarjeta quedaba pegada a la barra y su sombra se cortaba.
        'nav-m': 'calc(6rem + env(safe-area-inset-bottom))',
      },
    },
  },
  plugins: [],
}
