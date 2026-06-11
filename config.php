<?php
// ================= SHIM DE COMPATIBILIDAD PHP < 5.5 =================
// USBWebserver v8.6 utiliza PHP 5.4.15, donde password_hash() y password_verify() no existen.
// Este bloque define estas funciones usando crypt() con Blowfish/bcrypt de forma segura y compatible.
if (!function_exists('password_hash')) {
    if (!defined('PASSWORD_BCRYPT')) {
        define('PASSWORD_BCRYPT', 1);
    }
    
    function password_hash($password, $algo, array $options = []) {
        $cost = isset($options['cost']) ? sprintf('%02d', $options['cost']) : '10';
        
        // Generar una sal aleatoria válida para Blowfish (22 caracteres de './0-9A-Za-z')
        $salt_chars = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $salt = '';
        for ($i = 0; $i < 22; $i++) {
            $salt .= $salt_chars[mt_rand(0, 63)];
        }
        
        return crypt($password, '$2y$' . $cost . '$' . $salt);
    }
}

if (!function_exists('password_verify')) {
    function password_verify($password, $hash) {
        // crypt() en PHP extrae automáticamente la sal y el costo del hash provisto
        return crypt($password, $hash) === $hash;
    }
}
// ====================================================================

// Configuración de la base de datos para USBWebserver (y otros entornos locales como XAMPP/WAMP)
$db_host = '127.0.0.1'; // Usar IP directa a veces es más rápido que 'localhost' en Windows
$db_name = 'eleccion_madrina';

// Lista de puertos y contraseñas comunes para probar automáticamente
// USBWebserver suele usar puerto 3307 y contraseña 'usbw'
// XAMPP / MariaDB estándar suele usar puerto 3306 y contraseña vacía ''
$ports_to_try = [3307, 3306];
$passwords_to_try = ['usbw', ''];
$db_user = 'root';

$pdo = null;
$connected = false;
$last_error = '';
$db_connection_error = null;

foreach ($ports_to_try as $port) {
    foreach ($passwords_to_try as $password) {
        try {
            // Intentar conectar sin especificar base de datos primero (para poder crearla si no existe)
            $dsn = "mysql:host={$db_host};port={$port};charset=utf8mb4";
            $temp_pdo = new PDO($dsn, $db_user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Si conecta, crear la base de datos si no existe
            $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $temp_pdo->exec("USE `{$db_name}`");

            // Guardar conexión exitosa
            $pdo = $temp_pdo;
            $connected = true;
            break 2; // Salir de ambos bucles
        } catch (PDOException $e) {
            $last_error = $e->getMessage();
        }
    }
}

if (!$connected) {
    $db_connection_error = "No se pudo conectar al servidor MySQL en los puertos comunes. Detalle: " . $last_error;
} else {
    // ================= AUTO-CREACIÓN DE TABLAS Y AUTO-SEMILLERO =================
    try {
        // 1. Crear tabla de participantes
        $pdo->exec("CREATE TABLE IF NOT EXISTS `participantes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `nombre_completo` VARCHAR(255) NOT NULL,
            `grado` VARCHAR(100) NOT NULL,
            `seccion` VARCHAR(50) NOT NULL,
            `fotografia` TEXT NOT NULL,
            `votos` INT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 2. Crear tabla de usuarios
        $pdo->exec("CREATE TABLE IF NOT EXISTS `usuarios` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `usuario` VARCHAR(100) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 3. Crear tabla de configuracion para guardar el estado de las votaciones
        $pdo->exec("CREATE TABLE IF NOT EXISTS `configuracion` (
            `clave` VARCHAR(100) PRIMARY KEY,
            `valor` TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Insertar estado inicial de las votaciones ('cerrada')
        $check_config = $pdo->prepare("SELECT COUNT(*) FROM `configuracion` WHERE `clave` = ?");
        $check_config->execute(['estado_votacion']);
        if ($check_config->fetchColumn() == 0) {
            $insert_config = $pdo->prepare("INSERT INTO `configuracion` (`clave`, `valor`) VALUES (?, ?)");
            $insert_config->execute(['estado_votacion', 'cerrada']);
        }

        // Insertar tema por defecto ('dark')
        $check_theme = $pdo->prepare("SELECT COUNT(*) FROM `configuracion` WHERE `clave` = ?");
        $check_theme->execute(['tema_actual']);
        if ($check_theme->fetchColumn() == 0) {
            $insert_theme = $pdo->prepare("INSERT INTO `configuracion` (`clave`, `valor`) VALUES (?, ?)");
            $insert_theme->execute(['tema_actual', 'dark']);
        }

        // 3. Crear administrador 'admin' por defecto si no existe en la tabla de usuarios
        $check = $pdo->prepare("SELECT COUNT(*) FROM `usuarios` WHERE `usuario` = ?");
        $check->execute(['admin']);
        $count = $check->fetchColumn();
        
        if ($count == 0) {
            $default_user = 'admin';
            $default_pass = 'admin123';
            // Encriptar contraseña con bcrypt (compatible con el hash que usamos previamente)
            $hashed_pass = password_hash($default_pass, PASSWORD_BCRYPT, ['cost' => 10]);
            
            $insert = $pdo->prepare("INSERT INTO `usuarios` (`usuario`, `password`) VALUES (?, ?)");
            $insert->execute([$default_user, $hashed_pass]);
        }
    } catch (PDOException $e) {
        $db_connection_error = "Error al inicializar las tablas de la base de datos: " . $e->getMessage();
    }
}
?>
