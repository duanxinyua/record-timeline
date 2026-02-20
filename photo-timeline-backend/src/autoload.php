<?php
// src/autoload.php
// 简单的基于 PSR-4 风格的原生自动加载器

spl_autoload_register(function ($class) {
    // 项目命名空间前缀
    $prefix = 'App\\';
    // 基础目录
    $base_dir = __DIR__ . '/';

    // 检查这个类是否使用了命名空间前缀
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // 不匹配前缀，转由其他的注册函数处理
        return;
    }

    // 获取相对的类名
    $relative_class = substr($class, $len);

    // 将命名空间前缀替换为基础目录，将名称空间分隔符替换为目录分隔符
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    // 如果文件存在，载入文件
    if (file_exists($file)) {
        require $file;
    }
});
