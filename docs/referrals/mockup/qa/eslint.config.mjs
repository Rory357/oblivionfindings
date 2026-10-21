import globals from 'globals';
export default [{files:['**/*.jsx','**/*.js'],languageOptions:{ecmaVersion:'latest',sourceType:'module',parserOptions:{ecmaFeatures:{jsx:true}},globals:globals.browser},rules:{'no-undef':'error'}}];
