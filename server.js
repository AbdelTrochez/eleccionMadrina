const express = require('express');
const session = require('express-session');
const mysql = require('mysql2/promise');
const bcrypt = require('bcrypt');
const multer = require('multer');
const path = require('path');
const fs = require('fs');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// Asegurar que exista la carpeta de subidas de imágenes
const uploadsDir = path.join(__dirname, 'public', 'uploads');
if (!fs.existsSync(uploadsDir)) {
  fs.mkdirSync(uploadsDir, { recursive: true });
}

// Configuración de la conexión a la base de datos MySQL/MariaDB
const dbConfig = {
  host: process.env.DB_HOST || 'localhost',
  port: parseInt(process.env.DB_PORT || '3306'),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'eleccion_madrina',
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0
};

let pool;

async function connectDatabase() {
  try {
    pool = mysql.createPool(dbConfig);
    // Probar conexión
    const connection = await pool.getConnection();
    console.log(' Conectado exitosamente a la base de datos MySQL/MariaDB.');
    connection.release();

    // Auto-sembrado del administrador si la tabla usuarios está vacía
    await autoSeedAdmin();
  } catch (error) {
    console.error(' Error conectando a la base de datos. Asegúrate de configurar la base de datos y el archivo .env:', error.message);
  }
}

// Sembrar administrador por defecto (admin / admin123) si no existe ninguno
async function autoSeedAdmin() {
  try {
    const [rows] = await pool.query('SELECT COUNT(*) as count FROM usuarios');
    if (rows[0].count === 0) {
      const defaultUser = 'admin';
      const defaultPass = 'admin123';
      const saltRounds = 10;
      const hashedPassword = await bcrypt.hash(defaultPass, saltRounds);
      
      await pool.query(
        'INSERT INTO usuarios (usuario, password) VALUES (?, ?)',
        [defaultUser, hashedPassword]
      );
      console.log(` Administrador por defecto creado con éxito.`);
      console.log(`   Usuario: ${defaultUser}`);
      console.log(`   Contraseña: ${defaultPass}`);
    }
  } catch (err) {
    console.error(' Error en el auto-sembrado del administrador:', err.message);
  }
}

// Middleware para manejo de peticiones
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Configuración de Sesión Tradicional
app.use(session({
  secret: process.env.SESSION_SECRET || 'secret_madrina_session_key',
  resave: false,
  saveUninitialized: false,
  cookie: {
    httpOnly: true,
    maxAge: 24 * 60 * 60 * 1000 // 1 día
  }
}));

// Servir archivos estáticos del frontend
app.use(express.static(path.join(__dirname, 'public')));

// Middleware de Protección para Rutas de Administración
function requireAuth(req, res, next) {
  if (req.session && req.session.userId) {
    return next();
  }
  return res.status(401).json({ error: 'No autorizado. Inicie sesión por favor.' });
}

// Configuración de Multer para la subida de fotos
const storage = multer.diskStorage({
  destination: (req, file, cb) => {
    cb(null, uploadsDir);
  },
  filename: (req, file, cb) => {
    const uniqueSuffix = Date.now() + '-' + Math.round(Math.random() * 1e9);
    cb(null, uniqueSuffix + path.extname(file.originalname));
  }
});

const upload = multer({
  storage: storage,
  fileFilter: (req, file, cb) => {
    const filetypes = /jpeg|jpg|png|webp|gif/;
    const mimetype = filetypes.test(file.mimetype);
    const extname = filetypes.test(path.extname(file.originalname).toLowerCase());
    
    if (mimetype && extname) {
      return cb(null, true);
    }
    cb(new Error('Solo se permiten imágenes (jpeg, jpg, png, webp, gif)'));
  },
  limits: { fileSize: 5 * 1024 * 1024 } // Límite de 5MB
});

/* ==================== RUTAS DE NAVEGACIÓN ==================== */

// Ruta Vista Pública
app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// Ruta Vista de Administración
app.get('/admin', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'admin.html'));
});

/* ==================== ENDPOINTS DE LA API ==================== */

// POST /api/login: Manejo de sesión tradicional (Inicio de sesión)
app.post('/api/login', async (req, res) => {
  const { usuario, password } = req.body;

  if (!usuario || !password) {
    return res.status(400).json({ error: 'Usuario y contraseña son requeridos' });
  }

  try {
    const [rows] = await pool.query('SELECT * FROM usuarios WHERE usuario = ?', [usuario]);
    if (rows.length === 0) {
      return res.status(401).json({ error: 'Credenciales incorrectas' });
    }

    const user = rows[0];
    const match = await bcrypt.compare(password, user.password);
    if (!match) {
      return res.status(401).json({ error: 'Credenciales incorrectas' });
    }

    // Guardar variables de sesión
    req.session.userId = user.id;
    req.session.usuario = user.usuario;

    return res.json({ success: true, message: 'Inicio de sesión exitoso', usuario: user.usuario });
  } catch (error) {
    console.error('Error en login:', error);
    return res.status(500).json({ error: 'Error interno del servidor' });
  }
});

// GET /api/logout: Cierre de sesión
app.get('/api/logout', (req, res) => {
  req.session.destroy((err) => {
    if (err) {
      return res.status(500).json({ error: 'No se pudo cerrar la sesión' });
    }
    res.clearCookie('connect.sid');
    return res.json({ success: true, message: 'Sesión cerrada correctamente' });
  });
});

// GET /api/participantes: Obtener participantes ordenados (Público)
app.get('/api/participantes', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT id, nombre_completo, grado, seccion, fotografia, votos FROM participantes ORDER BY nombre_completo ASC');
    return res.json(rows);
  } catch (error) {
    console.error('Error al obtener participantes:', error);
    return res.status(500).json({ error: 'Error al obtener participantes de la base de datos' });
  }
});

// POST /api/votar/:id: Incrementar +1 el voto
app.post('/api/votar/:id', async (req, res) => {
  const { id } = req.params;
  try {
    const [result] = await pool.query('UPDATE participantes SET votos = votos + 1 WHERE id = ?', [id]);
    if (result.affectedRows === 0) {
      return res.status(404).json({ error: 'Participante no encontrada' });
    }
    return res.json({ success: true, message: 'Voto registrado exitosamente' });
  } catch (error) {
    console.error('Error al votar:', error);
    return res.status(500).json({ error: 'Error al registrar el voto' });
  }
});

/* ==================== RUTAS PROTEGIDAS DEL ADMIN ==================== */

// GET /api/admin/check-session: Verificar si el admin tiene sesión activa
app.get('/api/admin/check-session', (req, res) => {
  if (req.session && req.session.userId) {
    return res.json({ loggedIn: true, usuario: req.session.usuario });
  }
  return res.json({ loggedIn: false });
});

// GET /api/admin/resultados: Resultados ordenados de mayor a menor votos
app.get('/api/admin/resultados', requireAuth, async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT id, nombre_completo, grado, seccion, fotografia, votos FROM participantes ORDER BY votos DESC, nombre_completo ASC');
    return res.json(rows);
  } catch (error) {
    console.error('Error al obtener resultados:', error);
    return res.status(500).json({ error: 'Error al obtener resultados' });
  }
});

// POST /api/admin/participantes: Crear participante con subida de imagen
app.post('/api/admin/participantes', requireAuth, (req, res) => {
  upload.single('fotografia')(req, res, async (err) => {
    if (err) {
      return res.status(400).json({ error: err.message });
    }

    const { nombre_completo, grado, seccion } = req.body;

    if (!nombre_completo || !grado || !seccion) {
      // Eliminar el archivo subido si falla la validación
      if (req.file) {
        fs.unlinkSync(req.file.path);
      }
      return res.status(400).json({ error: 'Todos los campos son obligatorios' });
    }

    if (!req.file) {
      return res.status(400).json({ error: 'Debe subir una fotografía de la participante' });
    }

    // Guardar ruta relativa para que sea accesible en el cliente
    const fotografiaUrl = `/uploads/${req.file.filename}`;

    try {
      await pool.query(
        'INSERT INTO participantes (nombre_completo, grado, seccion, fotografia, votos) VALUES (?, ?, ?, ?, 0)',
        [nombre_completo, grado, seccion, fotografiaUrl]
      );
      return res.status(201).json({ success: true, message: 'Participante registrada con éxito' });
    } catch (error) {
      console.error('Error al crear participante:', error);
      // Eliminar archivo subido
      fs.unlinkSync(req.file.path);
      return res.status(500).json({ error: 'Error al guardar la participante en la base de datos' });
    }
  });
});

// DELETE /api/admin/participantes/:id: Eliminar participante
app.delete('/api/admin/participantes/:id', requireAuth, async (req, res) => {
  const { id } = req.params;

  try {
    // 1. Obtener la participante para saber el nombre de la fotografía
    const [rows] = await pool.query('SELECT fotografia FROM participantes WHERE id = ?', [id]);
    if (rows.length === 0) {
      return res.status(404).json({ error: 'Participante no encontrada' });
    }

    const fotografiaPath = rows[0].fotografia;

    // 2. Eliminar el archivo físico de imagen del servidor si existe
    if (fotografiaPath) {
      const fullPath = path.join(__dirname, 'public', fotografiaPath);
      if (fs.existsSync(fullPath)) {
        fs.unlinkSync(fullPath);
      }
    }

    // 3. Eliminar de la base de datos
    await pool.query('DELETE FROM participantes WHERE id = ?', [id]);

    return res.json({ success: true, message: 'Participante eliminada exitosamente' });
  } catch (error) {
    console.error('Error al eliminar participante:', error);
    return res.status(500).json({ error: 'Error al eliminar participante' });
  }
});

// PUT /api/admin/credenciales: Actualizar usuario/password del administrador actual
app.put('/api/admin/credenciales', requireAuth, async (req, res) => {
  const { nuevo_usuario, nuevo_password } = req.body;
  const adminId = req.session.userId;

  if (!nuevo_usuario && !nuevo_password) {
    return res.status(400).json({ error: 'Debe ingresar al menos un campo para actualizar (usuario o contraseña)' });
  }

  try {
    let updateQuery = 'UPDATE usuarios SET ';
    const queryParams = [];

    if (nuevo_usuario) {
      // Verificar si el usuario ya existe en la base de datos (y no es el actual)
      const [existing] = await pool.query('SELECT id FROM usuarios WHERE usuario = ? AND id != ?', [nuevo_usuario, adminId]);
      if (existing.length > 0) {
        return res.status(400).json({ error: 'El nombre de usuario ya está en uso' });
      }

      updateQuery += 'usuario = ?';
      queryParams.push(nuevo_usuario);
    }

    if (nuevo_password) {
      if (nuevo_usuario) updateQuery += ', ';
      const hashedPassword = await bcrypt.hash(nuevo_password, 10);
      updateQuery += 'password = ?';
      queryParams.push(hashedPassword);
    }

    updateQuery += ' WHERE id = ?';
    queryParams.push(adminId);

    await pool.query(updateQuery, queryParams);

    // Actualizar nombre de usuario en la sesión si cambió
    if (nuevo_usuario) {
      req.session.usuario = nuevo_usuario;
    }

    return res.json({ success: true, message: 'Credenciales actualizadas exitosamente' });
  } catch (error) {
    console.error('Error al cambiar credenciales:', error);
    return res.status(500).json({ error: 'Error al actualizar las credenciales' });
  }
});

// Iniciar base de datos y servidor
connectDatabase().then(() => {
  app.listen(PORT, () => {
    console.log(` Servidor corriendo exitosamente.`);
    console.log(`   Local: http://localhost:${PORT}`);
    console.log(`   Admin Panel: http://localhost:${PORT}/admin`);
  });
});
