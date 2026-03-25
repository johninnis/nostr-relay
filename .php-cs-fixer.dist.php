<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php');

$config = new PhpCsFixer\Config();
return $config->setRules([
    '@Symfony' => true,
    '@Symfony:risky' => true,
    'declare_strict_types' => true,
    'final_class' => true,
    'final_public_method_for_abstract_class' => true,
    'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
    'native_constant_invocation' => ['fix_built_in' => false, 'include' => [], 'exclude' => []],
    'native_function_invocation' => ['include' => [], 'exclude' => []],
    'no_unused_imports' => true,
    'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
    'strict_comparison' => true,
    'strict_param' => true,
])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
