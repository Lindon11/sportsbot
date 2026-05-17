import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default [
    {
        languageOptions: {
            globals: {
                ...globals.browser,
                ...globals.es2022,
            },
        },
    },
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        rules: {
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_', caughtErrorsIgnorePattern: '^_' }],
            'vue/multi-word-component-names': 'off',
            'vue/no-unused-vars': 'warn',
            // Pre-existing issues — warn only so CI can pass while backlog exists
            'no-empty': ['warn', { allowEmptyCatch: true }],
            'vue/require-toggle-inside-transition': 'warn',
            // Style rules — warn only, don't fail CI
            'vue/max-attributes-per-line': 'warn',
            'vue/singleline-html-element-content-newline': 'warn',
            'vue/html-self-closing': 'warn',
            'vue/attributes-order': 'warn',
        },
    },
]
