/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.svelte',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Satoshi', 'system-ui', '-apple-system', 'sans-serif'],
            },
            colors: {
                brand: {
                    indigo: '#4F46E5',
                    cyan: '#06B6D4',
                    slate: '#64748B',
                    ice: '#F1F5F9',
                },
                dark: {
                    bg: '#0B0F17',
                    'bg-alt': '#0F172A',
                    surface: '#151D2C',
                    'surface-elevated': '#1E293B',
                    border: '#26334D',
                    'border-subtle': '#334155',
                },
            },
        },
    },
    plugins: [],
};
