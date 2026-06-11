-- Creación de la base de datos (opcional, si no existe)
-- CREATE DATABASE IF NOT EXISTS eleccion_madrina;
-- USE eleccion_madrina;

-- Tabla de participantes (candidatas)
CREATE TABLE IF NOT EXISTS `participantes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre_completo` VARCHAR(255) NOT NULL,
  `grado` VARCHAR(100) NOT NULL,
  `seccion` VARCHAR(50) NOT NULL,
  `fotografia` TEXT NOT NULL,
  `votos` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de usuarios (administradores)
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar usuario administrador por defecto (usuario: admin, password: admin123)
-- La contraseña está encriptada con bcrypt (10 rounds)
INSERT INTO `usuarios` (`usuario`, `password`)
VALUES ('admin', '$2b$10$wVb7h8.wH0i5tK66u72D3.85x7M1Rk2v9tJ7iJdpx9x9DkH39V6mS')
ON DUPLICATE KEY UPDATE `usuario` = `usuario`;
