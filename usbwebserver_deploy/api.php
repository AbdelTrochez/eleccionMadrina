<?php
// Configurar cabeceras de respuesta y codificación
header('Content-Type: application/json; charset=utf-8');

// Iniciar sesión tradicional
if (session_status() === PHP_SESSION_NONE) {
    // Configurar cookies de sesión para que expiren en 1 día
    ini_set('session.cookie_lifetime', 86400);
    ini_set('session.gc_maxlifetime', 86400);
    session_start();
}

// Requerir archivo de configuración y conexión PDO a la base de datos
require_once 'config.php';

// Si ocurrió un error de conexión a la base de datos, retornar un JSON descriptivo de inmediato
if (isset($db_connection_error) && !empty($db_connection_error)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error de Conexión a Base de Datos. ' . $db_connection_error . '. Asegúrate de que MySQL esté activo en USBWebserver.'
    ]);
    exit;
}

// Asegurar la existencia de la carpeta de subidas de imágenes
$uploads_dir = __DIR__ . '/uploads';
if (!file_exists($uploads_dir)) {
    mkdir($uploads_dir, 0777, true);
}

// Obtener el tipo de acción
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Helper para leer peticiones JSON
function getJsonInput() {
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    return is_array($data) ? $data : [];
}

// Helper para requerir autenticación
function requireAuth() {
    if (!isset($_SESSION['userId'])) {
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado. Inicie sesión por favor.']);
        exit;
    }
}

// Ruteador de acciones
switch ($action) {
    
    // 1. POST /api.php?action=login
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Método no permitido']);
            exit;
        }

        // Obtener datos (ya sea de JSON o de $_POST estándar)
        $input = getJsonInput();
        $usuario = isset($input['usuario']) ? trim($input['usuario']) : (isset($_POST['usuario']) ? trim($_POST['usuario']) : '');
        $password = isset($input['password']) ? $input['password'] : (isset($_POST['password']) ? $_POST['password'] : '');

        if (empty($usuario) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Usuario y contraseña son requeridos']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM `usuarios` WHERE `usuario` = ?");
            $stmt->execute([$usuario]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Registrar variables de sesión
                $_SESSION['userId'] = $user['id'];
                $_SESSION['usuario'] = $user['usuario'];

                echo json_encode([
                    'success' => true,
                    'message' => 'Inicio de sesión exitoso',
                    'usuario' => $user['usuario']
                ]);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Credenciales incorrectas']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error interno de la base de datos']);
        }
        break;

    // 2. GET /api.php?action=logout
    case 'logout':
        // Destruir la sesión
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Sesión cerrada correctamente']);
        break;

    // 3. GET /api.php?action=check-session
    case 'check-session':
        if (isset($_SESSION['userId'])) {
            echo json_encode([
                'loggedIn' => true,
                'usuario' => $_SESSION['usuario']
            ]);
        } else {
            echo json_encode(['loggedIn' => false]);
        }
        break;

    // 4. GET /api.php?action=participantes
    case 'participantes':
        try {
            $stmt = $pdo->query("SELECT `id`, `nombre_completo`, `grado`, `seccion`, `fotografia`, `votos` FROM `participantes` ORDER BY `nombre_completo` ASC");
            $participantes = $stmt->fetchAll();
            echo json_encode($participantes);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al obtener participantes']);
        }
        break;

    // 5. POST /api.php?action=votar&id=X
    case 'votar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Método no permitido']);
            exit;
        }

        $input = getJsonInput();
        $id = isset($_GET['id']) ? intval($_GET['id']) : (isset($input['id']) ? intval($input['id']) : 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de participante no válido']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE `participantes` SET `votos` = `votos` + 1 WHERE `id` = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Voto registrado exitosamente']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Participante no encontrada']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al registrar el voto']);
        }
        break;

    /* ==================== RUTAS PROTEGIDAS DEL ADMIN ==================== */

    // 6. GET /api.php?action=admin/resultados
    case 'admin/resultados':
        requireAuth();
        try {
            $stmt = $pdo->query("SELECT `id`, `nombre_completo`, `grado`, `seccion`, `fotografia`, `votos` FROM `participantes` ORDER BY `votos` DESC, `nombre_completo` ASC");
            $resultados = $stmt->fetchAll();
            echo json_encode($resultados);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al obtener resultados']);
        }
        break;

    // 7. POST /api.php?action=admin/participantes (Crear participante con subida de imagen)
    case 'admin/participantes':
        requireAuth();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Método no permitido']);
            exit;
        }

        $nombre_completo = isset($_POST['nombre_completo']) ? trim($_POST['nombre_completo']) : '';
        $grado = isset($_POST['grado']) ? trim($_POST['grado']) : '';
        $seccion = isset($_POST['seccion']) ? trim($_POST['seccion']) : '';

        if (empty($nombre_completo) || empty($grado) || empty($seccion)) {
            http_response_code(400);
            echo json_encode(['error' => 'Todos los campos son obligatorios']);
            exit;
        }

        // Procesar archivo de imagen
        if (!isset($_FILES['fotografia']) || $_FILES['fotografia']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'Debe subir una fotografía de la participante']);
            exit;
        }

        $file = $_FILES['fotografia'];
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
        
        // Obtener tipo MIME de forma compatible y segura (evitando dependencia de finfo_open)
        $mime_type = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        } elseif (function_exists('getimagesize')) {
            $size = getimagesize($file['tmp_name']);
            if ($size && isset($size['mime'])) {
                $mime_type = $size['mime'];
            }
        } else {
            // Fallback por extensión si no hay funciones de verificación disponibles
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mimes = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp'
            ];
            if (isset($mimes[$ext])) {
                $mime_type = $mimes[$ext];
            }
        }

        if (!in_array($mime_type, $allowed_types)) {
            http_response_code(400);
            echo json_encode(['error' => 'Solo se permiten imágenes (jpeg, png, webp, gif)']);
            exit;
        }

        // Generar nombre de archivo único para evitar colisiones
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = time() . '-' . mt_rand(100000, 999999) . '.' . strtolower($ext);
        $destination = $uploads_dir . '/' . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Guardar ruta relativa en la base de datos
            $fotografia_url = 'uploads/' . $filename;

            try {
                $stmt = $pdo->prepare("INSERT INTO `participantes` (`nombre_completo`, `grado`, `seccion`, `fotografia`, `votos`) VALUES (?, ?, ?, ?, 0)");
                $stmt->execute([$nombre_completo, $grado, $seccion, $fotografia_url]);

                http_response_code(201);
                echo json_encode(['success' => true, 'message' => 'Participante registrada con éxito']);
            } catch (PDOException $e) {
                // Borrar el archivo si falla la base de datos
                if (file_exists($destination)) {
                    unlink($destination);
                }
                http_response_code(500);
                echo json_encode(['error' => 'Error al guardar la participante en la base de datos']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Error al guardar el archivo en el servidor']);
        }
        break;

    // 8. DELETE /api.php?action=admin/delete-participante&id=X (o POST si DELETE no es soportado)
    case 'admin/delete-participante':
        requireAuth();
        
        $input = getJsonInput();
        $id = isset($_GET['id']) ? intval($_GET['id']) : (isset($input['id']) ? intval($input['id']) : 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de participante no válido']);
            exit;
        }

        try {
            // 1. Obtener la ruta del archivo físico de la imagen
            $stmt = $pdo->prepare("SELECT `fotografia` FROM `participantes` WHERE `id` = ?");
            $stmt->execute([$id]);
            $cand = $stmt->fetch();

            if (!$cand) {
                http_response_code(404);
                echo json_encode(['error' => 'Participante no encontrada']);
                exit;
            }

            // 2. Eliminar el archivo físico si existe
            $fotografia_path = $cand['fotografia'];
            if (!empty($fotografia_path)) {
                $full_path = __DIR__ . '/' . $fotografia_path;
                if (file_exists($full_path) && is_file($full_path)) {
                    unlink($full_path);
                }
            }

            // 3. Eliminar de la base de datos
            $delete_stmt = $pdo->prepare("DELETE FROM `participantes` WHERE `id` = ?");
            $delete_stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Participante eliminada exitosamente']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al intentar eliminar de la base de datos']);
        }
        break;

    // 9. PUT/POST /api.php?action=admin/credenciales
    case 'admin/credenciales':
        requireAuth();
        
        $input = getJsonInput();
        $nuevo_usuario = isset($input['nuevo_usuario']) ? trim($input['nuevo_usuario']) : (isset($_POST['nuevo_usuario']) ? trim($_POST['nuevo_usuario']) : '');
        $nuevo_password = isset($input['nuevo_password']) ? $input['nuevo_password'] : (isset($_POST['nuevo_password']) ? $_POST['nuevo_password'] : '');
        $admin_id = $_SESSION['userId'];

        if (empty($nuevo_usuario) && empty($nuevo_password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Debe ingresar al menos un campo para actualizar']);
            exit;
        }

        try {
            $update_fields = [];
            $params = [];

            if (!empty($nuevo_usuario)) {
                // Verificar si ya existe otro usuario con el mismo nombre
                $check = $pdo->prepare("SELECT `id` FROM `usuarios` WHERE `usuario` = ? AND `id` != ?");
                $check->execute([$nuevo_usuario, $admin_id]);
                if ($check->fetch()) {
                    http_response_code(400);
                    echo json_encode(['error' => 'El nombre de usuario ya está en uso']);
                    exit;
                }

                $update_fields[] = "`usuario` = ?";
                $params[] = $nuevo_usuario;
            }

            if (!empty($nuevo_password)) {
                $hashed_pass = password_hash($nuevo_password, PASSWORD_BCRYPT, ['cost' => 10]);
                $update_fields[] = "`password` = ?";
                $params[] = $hashed_pass;
            }

            $params[] = $admin_id;
            $query = "UPDATE `usuarios` SET " . implode(", ", $update_fields) . " WHERE `id` = ?";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            // Actualizar el nombre de usuario en la sesión si cambió
            if (!empty($nuevo_usuario)) {
                $_SESSION['usuario'] = $nuevo_usuario;
            }

            echo json_encode(['success' => true, 'message' => 'Credenciales actualizadas exitosamente']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Error al actualizar credenciales']);
        }
        break;

    // Acción no encontrada
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Acción no soportada']);
        break;
}
?>
