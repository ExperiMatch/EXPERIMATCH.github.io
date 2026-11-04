<?php
require_once __DIR__ . '/database.php';

if (isset($_GET['accion']) && $_GET['accion'] === 'salir') {
    cerrarSesionUsuario();
    header('Location: index.php');
    exit;
}

$modo = ($_GET['modo'] ?? 'login') === 'registro' ? 'registro' : 'login';
$error = '';
$ok = '';
$avisoTexto = '';
$old = [
    'documento' => '',
    'nombre' => '',
    'fecha' => '',
    'correo' => '',
    'tipo_documento' => '0',
    'genero' => '0'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'login') {
        $correo = trim($_POST['correo'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if ($correo === '' || $password === '') {
            $error = 'Completa tu correo y contraseña.';
            $modo = 'login';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ingresa un correo electrónico válido.';
            $modo = 'login';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT * FROM usuario WHERE correo = ? LIMIT 1');
                $stmt->execute([$correo]);
                $usuario = $stmt->fetch();
                if ($usuario && !empty($usuario['password_hash']) && password_verify($password, $usuario['password_hash'])) {
                    iniciarSesionUsuario($usuario);
                    header('Location: index.php');
                    exit;
                }
                $error = 'Correo o contraseña incorrectos.';
                $modo = 'login';
            } catch (PDOException $e) {
                $error = 'No pudimos iniciar sesión. Verifica que MySQL esté iniciado en XAMPP.';
                $modo = 'login';
            }
        }
    }
    if ($accion === 'registro') {
        $old['documento'] = trim($_POST['documento'] ?? '');
        $old['nombre'] = trim($_POST['nombre'] ?? '');
        $old['fecha'] = $_POST['fecha'] ?? '';
        $old['correo'] = trim($_POST['correo'] ?? '');
        $old['tipo_documento'] = (string)(int)($_POST['tipo_documento'] ?? 0);
        $old['genero'] = (string)(int)($_POST['genero'] ?? 0);
        $documento = $old['documento'];
        $nombre = $old['nombre'];
        $fecha = $old['fecha'];
        $correo = $old['correo'];
        $tipoDocumento = (int)$old['tipo_documento'];
        $genero = (int)$old['genero'];
        $password = (string)($_POST['password'] ?? '');
        $confirmacion = (string)($_POST['password_confirmacion'] ?? '');
        $modo = 'registro';
        if ($documento === '' || $nombre === '' || $fecha === '' || $correo === '' || $password === '' || $confirmacion === '') {
            $error = 'Completa todos los campos.';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ingresa un correo electrónico válido.';
        } elseif ($tipoDocumento <= 0 || $genero <= 0) {
            $error = 'Selecciona el tipo de documento y el género.';
        } elseif (strlen($password) < 6) {
            $error = 'La contraseña debe tener mínimo 6 caracteres.';
        } elseif ($password !== $confirmacion) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT * FROM usuario WHERE correo = ? OR Numero_de_Documento = ? LIMIT 1');
                $stmt->execute([$correo, $documento]);
                $existente = $stmt->fetch();
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($existente) {
                    $mismoCorreo = mb_strtolower(trim((string)($existente['correo'] ?? ''))) === mb_strtolower($correo);
                    $mismoDocumento = (string)($existente['Numero_de_Documento'] ?? '') === $documento;
                    if (!$mismoCorreo || !$mismoDocumento) {
                        $error = 'El correo o número de documento ya pertenece a otra cuenta.';
                    } elseif (!empty($existente['password_hash'])) {
                        $error = 'Esta cuenta ya existe. Usa Iniciar sesión.';
                        $modo = 'login';
                    } else {
                        $stmt = $pdo->prepare('UPDATE usuario SET nombre_completo = ?, fecha_nacimiento = ?, correo = ?, id_tipo_documento = ?, id_genero = ?, password_hash = ? WHERE id_usuario = ?');
                        $stmt->execute([$nombre, $fecha, $correo, $tipoDocumento, $genero, $hash, (int)$existente['id_usuario']]);
                        $stmt = $pdo->prepare('SELECT * FROM usuario WHERE id_usuario = ? LIMIT 1');
                        $stmt->execute([(int)$existente['id_usuario']]);
                        $usuario = $stmt->fetch();
                        if (!$usuario) {
                            throw new RuntimeException('No se pudo recuperar la cuenta creada.');
                        }
                        iniciarSesionUsuario($usuario);
                        header('Location: index.php');
                        exit;
                    }
                } else {
                    $stmt = $pdo->prepare('INSERT INTO usuario (Numero_de_Documento, nombre_completo, fecha_nacimiento, correo, id_tipo_documento, id_genero, password_hash) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$documento, $nombre, $fecha, $correo, $tipoDocumento, $genero, $hash]);
                    $nuevoId = (int)$pdo->lastInsertId();
                    if ($nuevoId < 1) {
                        throw new RuntimeException('La tabla usuario debe tener id_usuario como AUTO_INCREMENT.');
                    }
                    $stmt = $pdo->prepare('SELECT * FROM usuario WHERE id_usuario = ? LIMIT 1');
                    $stmt->execute([$nuevoId]);
                    $usuario = $stmt->fetch();
                    if (!$usuario) {
                        throw new RuntimeException('No se pudo recuperar la cuenta creada.');
                    }
                    iniciarSesionUsuario($usuario);
                    header('Location: index.php');
                    exit;
                }
            } catch (PDOException $e) {
                if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
                    $error = 'Ese correo o documento ya está registrado. Prueba con otros datos o inicia sesión.';
                } else {
                    $error = 'No se pudo crear la cuenta. Revisa que las tablas de ExperiMatch estén disponibles.';
                }
            } catch (Throwable $e) {
                $error = 'No se pudo completar el registro. Inténtalo nuevamente.';
            }
        }
    }
}
if (isset($_GET['mensaje'])) {
    $avisos = [
        'favorito' => 'Inicia sesión para guardar experiencias en tus favoritos.',
        'visita' => 'Inicia sesión para registrar tus visitas.',
        'perfil' => 'Inicia sesión para ver y editar tu perfil.'
    ];
    $avisoTexto = $avisos[$_GET['mensaje']] ?? '';
}
$tiposDocumento = [];
$generos = [];
try {
    $tiposDocumento = $pdo->query('SELECT id_tipo_documento, Tipo_Documento FROM tipo_documento ORDER BY Tipo_Documento')->fetchAll();
    $generos = $pdo->query('SELECT id_genero, tipo_genero FROM genero ORDER BY tipo_genero')->fetchAll();
} catch (PDOException $e) {
    $error = $error ?: 'No se pudieron cargar los datos del formulario.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $modo === 'registro' ? 'Crear cuenta' : 'Iniciar sesión' ?> · ExperiMatch</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=20261104">
</head>
<body class="auth-page">
<div class="bg-orb orb-one"></div>
<div class="bg-orb orb-two"></div>
<div class="bg-orb orb-three"></div>
<div class="sparkles" aria-hidden="true">
<span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
</div>
<header class="navbar auth-nav">
<a href="index.php" class="logo">
<span>Experi</span><strong>Match</strong><small>✦</small>
</a>
<nav class="main-nav">
<a href="index.php">Inicio</a>
<a href="index.php#explorar">Explorar</a>
<a href="<?= $modo === 'registro' ? 'login.php' : 'login.php?modo=registro' ?>">
<?= $modo === 'registro' ? 'Iniciar sesión' : 'Crear cuenta' ?>
</a>
</nav>
<button class="theme-button" type="button" id="themeButton" aria-label="Cambiar tema">☾</button>
</header>
<main class="auth-layout">
<section class="auth-intro">
<span class="eyebrow">EXPERIMATCH ✦</span>
<h1><?= $modo === 'registro' ? 'Haz espacio para nuevas experiencias.' : 'Qué bueno volver a verte.' ?></h1>
<p><?= $modo === 'registro' ? 'Crea tu cuenta y empieza a guardar esos lugares que sí te dan ganas de salir.' : 'Tu perfil, tus favoritos y tus visitas están esperándote.' ?></p>
<div class="intro-pills">
<span>♥ Favoritos</span>
<span>✦ Tu perfil</span>
<span>📍 Tus visitas</span>
</div>
<div class="intro-note">
<strong>Tu cuenta conecta toda la página.</strong>
<span>Lo que guardes aquí también aparece en tus experiencias y favoritos.</span>
</div>
</section>
<section class="auth-card">
<div class="auth-card-top">
<span>01</span>
<span><?= $modo === 'registro' ? 'CUENTA NUEVA' : 'BIENVENIDO' ?></span>
</div>
<div class="auth-header">
<span><?= $modo === 'registro' ? 'UN PASO PARA EMPEZAR' : 'ENTRA A TU ESPACIO' ?></span>
<h2><?= $modo === 'registro' ? 'Crear cuenta' : 'Iniciar sesión' ?></h2>
<p><?= $modo === 'registro' ? 'Usa tus datos para guardar tus experiencias y construir tu perfil.' : 'Entra con el correo y contraseña de tu cuenta.' ?></p>
</div>
<?php if ($avisoTexto): ?><div class="alert info"><?= e($avisoTexto) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($ok): ?><div class="alert success"><?= e($ok) ?></div><?php endif; ?>

<?php if ($modo === 'registro'): ?>
<form method="post" class="auth-form" autocomplete="on">
<input type="hidden" name="accion" value="registro">
<div class="form-row">
<label>Número de documento<input type="text" name="documento" value="<?= e($old['documento']) ?>" required></label>
<label>Nombre completo<input type="text" name="nombre" value="<?= e($old['nombre']) ?>" required></label>
</div>
<div class="form-row">
<label>Fecha de nacimiento<input type="date" name="fecha" value="<?= e($old['fecha']) ?>" required></label>
<label>Correo electrónico<input type="email" name="correo" value="<?= e($old['correo']) ?>" required></label>
</div>
<div class="form-row">
<label>Tipo de documento
<select name="tipo_documento" required>
<option value="0">Selecciona</option>
<?php foreach ($tiposDocumento as $tipoDoc): ?>
<option value="<?= (int)$tipoDoc['id_tipo_documento'] ?>" <?= (int)$old['tipo_documento'] === (int)$tipoDoc['id_tipo_documento'] ? 'selected' : '' ?>><?= e($tipoDoc['Tipo_Documento']) ?></option>
<?php endforeach; ?>
</select>
</label>
<label>Género
<select name="genero" required>
<option value="0">Selecciona</option>
<?php foreach ($generos as $genero): ?>
<option value="<?= (int)$genero['id_genero'] ?>" <?= (int)$old['genero'] === (int)$genero['id_genero'] ? 'selected' : '' ?>><?= e($genero['tipo_genero']) ?></option>
<?php endforeach; ?>
</select>
</label>
</div>
<label>Contraseña<input type="password" name="password" minlength="6" autocomplete="new-password" required></label>
<label>Confirmar contraseña<input type="password" name="password_confirmacion" minlength="6" autocomplete="new-password" required></label>
<button type="submit" class="btn-primary full">Crear mi cuenta <span>→</span></button>
</form>
<p class="auth-switch">¿Ya tienes cuenta? <a href="login.php">Iniciar sesión</a></p>
<?php else: ?>
<form method="post" class="auth-form" autocomplete="on">
<input type="hidden" name="accion" value="login">
<label>Correo electrónico<input type="email" name="correo" placeholder="tucorreo@email.com" autocomplete="email" required></label>
<label>Contraseña<input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required></label>
<button type="submit" class="btn-primary full">Entrar a mi cuenta <span>→</span></button>
</form>
<p class="auth-switch">¿No tienes cuenta? <a href="login.php?modo=registro">Crear una cuenta</a></p>
<?php endif; ?>
<a href="index.php" class="back-home">← Volver a ExperiMatch</a>
</section>
</main>
<script>
const themeButton = document.getElementById('themeButton');
const savedTheme = localStorage.getItem('experimatch-theme');
if (savedTheme === 'light') { document.body.classList.add('light-theme'); }
function updateThemeButton() {
    themeButton.textContent = document.body.classList.contains('light-theme') ? '☀' : '☾';
}
updateThemeButton();
themeButton.addEventListener('click', () => {
    document.body.classList.toggle('light-theme');
    localStorage.setItem('experimatch-theme', document.body.classList.contains('light-theme') ? 'light' : 'dark');
    updateThemeButton();
});
</script>
</body>
</html>