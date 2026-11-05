/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './pages/**/*.php',
    './config/**/*.php',
    './includes/**/*.php',
    './components/**/*.php',
    './assets/js/**/*.js',
  ],
  safelist: [
    'bg-primary',
    'bg-primary-container',
    'text-primary',
    'text-secondary',

    'bg-blue-500',
    'bg-amber-500',
    'bg-emerald-600',
    'bg-red-500',
    'bg-zinc-400',

    'text-amber-500',
    'text-emerald-600',
    'text-blue-500',

    'bg-zinc-100',
    'text-zinc-700',
    'bg-amber-100',
    'text-amber-700',
    'bg-red-100',
    'text-red-700',
    'bg-blue-100',
    'text-blue-700',
    'bg-emerald-100',
    'text-emerald-700',

    'line-through',
    'hidden',
    'md:flex',
    'md:block',
    'md:ml-64',
    'lg:grid-cols-2',
    'lg:grid-cols-4'
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: '#bb0400',
        'primary-container': '#ea0600',
        'on-primary': '#ffffff',
        surface: '#fcf9f7',
        'surface-container-lowest': '#ffffff',
        'surface-container-low': '#f6f3f1',
        'surface-container': '#f0edeb',
        'surface-container-high': '#eae8e6',
        'surface-container-highest': '#e5e2e0',
        'on-surface': '#1b1c1b',
        secondary: '#5f5e5d',
        'secondary-container': '#e2dfdd',
        outline: '#956d67',
        'outline-variant': '#ebbcb4',
        error: '#ba1a1a',
      },
      fontFamily: {
        headline: ['Epilogue', 'sans-serif'],
        body: ['Inter', 'sans-serif'],
        label: ['Inter', 'sans-serif'],
      },
      borderRadius: {
        DEFAULT: '0.125rem',
        sm: '0.125rem',
        md: '0.25rem',
        lg: '0.25rem',
        xl: '0.5rem',
        full: '0.75rem',
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
  ],
}