
<?php
require_once __DIR__ . '/database.php';

requiereLogin();

$usuario = usuarioActual();

if (!$usuario) {
    header('Location: login.php?modo=login');
    exit;
}

$id = (int)$usuario['id_usuario'];
$mensaje = '';
$error = '';

/* =====================================================
   ACTUALIZAR INFORMACIÓN DEL PERFIL
===================================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'editar') {
        $nombre = trim($_POST['nombre'] ?? '');
        $fecha = trim($_POST['fecha'] ?? '');
        $correo = trim($_POST['correo'] ?? '');

        if ($nombre === '' || $fecha === '' || $correo === '') {
            $error = 'Completa todos los campos.';
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ingresa un correo válido.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT id_usuario FROM usuario
                 WHERE LOWER(correo) = LOWER(?)
                 AND id_usuario <> ?
                 LIMIT 1'
            );
            $stmt->execute([$correo, $id]);

            if ($stmt->fetch()) {
                $error = 'Ese correo ya está siendo utilizado.';
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE usuario
                     SET nombre_completo = ?,
                         fecha_nacimiento = ?,
                         correo = ?
                     WHERE id_usuario = ?'
                );

                $stmt->execute([
                    $nombre,
                    $fecha,
                    $correo,
                    $id
                ]);

                $mensaje = 'Tu perfil se actualizó correctamente.';
                $usuario = usuarioActual();
            }
        }
    }

    /* =================================================
       CAMBIAR FOTO DE PERFIL
    ================================================= */

    if ($accion === 'foto') {
        if (
            isset($_FILES['foto']) &&
            $_FILES['foto']['error'] === UPLOAD_ERR_OK
        ) {
            if ($_FILES['foto']['size'] > 5 * 1024 * 1024) {
                $error = 'La imagen debe pesar máximo 5 MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['foto']['tmp_name']);

                $permitidas = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($permitidas[$mime])) {
                    $error = 'Usa una imagen JPG, PNG o WEBP.';
                } else {
                    $dir = __DIR__ . '/uploads/perfiles';

                    if (!is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }

                    $nombreArchivo = 'usuario_' . $id . '_' .
                        time() . '.' . $permitidas[$mime];

                    $destino = $dir . '/' . $nombreArchivo;

                    if (
                        move_uploaded_file(
                            $_FILES['foto']['tmp_name'],
                            $destino
                        )
                    ) {
                        $ruta = 'uploads/perfiles/' . $nombreArchivo;

                        $stmt = $pdo->prepare(
                            'UPDATE usuario
                             SET foto_perfil = ?
                             WHERE id_usuario = ?'
                        );

                        $stmt->execute([$ruta, $id]);

                        $mensaje = 'Foto de perfil actualizada.';
                        $usuario = usuarioActual();
                    } else {
                        $error = 'No se pudo guardar la fotografía.';
                    }
                }
            }
        } else {
            $error = 'Selecciona una imagen.';
        }
    }

    /* =================================================
       ELIMINAR UN FAVORITO
    ================================================= */

    if ($accion === 'quitar_favorito') {
        $idLugar = (int)($_POST['id_lugar'] ?? 0);

        if ($idLugar > 0) {
            $stmt = $pdo->prepare(
                'DELETE FROM favoritos
                 WHERE id_usuario = ?
                 AND id_lugares = ?'
            );

            $stmt->execute([$id, $idLugar]);
        }

        header('Location: perfil.php#favoritos');
        exit;
    }
}

if (isset($_GET['bienvenida'])) {
    $mensaje = '¡Bienvenido a tu perfil de ExperiMatch!';
}

/* =====================================================
   ESTADÍSTICAS DEL USUARIO
===================================================== */

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM favoritos WHERE id_usuario = ?'
);
$stmt->execute([$id]);
$totalFavoritos = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM usuario_lugares WHERE id_usuario = ?'
);
$stmt->execute([$id]);
$totalVisitas = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM resena WHERE id_usuario = ?'
);
$stmt->execute([$id]);
$totalResenas = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT id_lugares)
     FROM usuario_lugares
     WHERE id_usuario = ?'
);
$stmt->execute([$id]);
$experienciasDistintas = (int)$stmt->fetchColumn();

/* =====================================================
   CONSULTAR FAVORITOS SIN EXIGIR fecha_guardado
===================================================== */

$columnasFavoritos = [];

try {
    $consultaColumnas = $pdo->query(
        'SHOW COLUMNS FROM favoritos'
    );

    foreach ($consultaColumnas->fetchAll(PDO::FETCH_ASSOC) as $columna) {
        $columnasFavoritos[] = $columna['Field'];
    }
} catch (Throwable $e) {
    $columnasFavoritos = [];
}

$ordenFavoritos = '';

if (in_array('fecha_guardado', $columnasFavoritos, true)) {
    $ordenFavoritos = ' ORDER BY f.fecha_guardado DESC';
} elseif (in_array('id_favorito', $columnasFavoritos, true)) {
    $ordenFavoritos = ' ORDER BY f.id_favorito DESC';
} elseif (in_array('id_lugares', $columnasFavoritos, true)) {
    $ordenFavoritos = ' ORDER BY f.id_lugares DESC';
}

$sqlFavoritos = '
    SELECT
        l.*,
        c.nombre_ciudad,
        tl.tipo_lugar
    FROM favoritos f
    INNER JOIN lugares l
        ON l.id_lugares = f.id_lugares
    INNER JOIN ciudad c
        ON c.id_ciudad = l.id_ciudad
    INNER JOIN tipo_lugar tl
        ON tl.id_tipo_lugar = l.id_tipo_lugar
    WHERE f.id_usuario = ?
    ' . $ordenFavoritos;

$stmt = $pdo->prepare($sqlFavoritos);
$stmt->execute([$id]);
$favoritos = $stmt->fetchAll();

foreach ($favoritos as &$favorito) {
    $precio = precioLugar(
        $favorito['nombre_lugar'],
        $favorito['tipo_lugar']
    );

    $favorito['precio_corto'] = $precio['corto'];
}

unset($favorito);

/* =====================================================
   HISTORIAL DE VISITAS
===================================================== */

$stmt = $pdo->prepare(
    'SELECT
        l.id_lugares,
        l.nombre_lugar,
        l.descripcion,
        l.calle,
        l.carrera,
        l.numero,
        c.nombre_ciudad,
        tl.tipo_lugar,
        MAX(ul.fecha_visita) AS ultima_visita,
        COUNT(*) AS veces
    FROM usuario_lugares ul
    INNER JOIN lugares l
        ON l.id_lugares = ul.id_lugares
    INNER JOIN ciudad c
        ON c.id_ciudad = l.id_ciudad
    INNER JOIN tipo_lugar tl
        ON tl.id_tipo_lugar = l.id_tipo_lugar
    WHERE ul.id_usuario = ?
    GROUP BY
        l.id_lugares,
        l.nombre_lugar,
        l.descripcion,
        l.calle,
        l.carrera,
        l.numero,
        c.nombre_ciudad,
        tl.tipo_lugar
    ORDER BY ultima_visita DESC
    LIMIT 12'
);

$stmt->execute([$id]);
$visitas = $stmt->fetchAll();

/* =====================================================
   DATOS VISUALES DEL PERFIL
===================================================== */

$foto = trim((string)($usuario['foto_perfil'] ?? ''));

$inicial = mb_strtoupper(
    mb_substr(
        (string)$usuario['nombre_completo'],
        0,
        1,
        'UTF-8'
    ),
    'UTF-8'
);

function edadActual(?string $fecha): string {
    if (!$fecha) {
        return '—';
    }

    try {
        $nacimiento = new DateTime($fecha);
        $hoy = new DateTime();

        return (string)$nacimiento->diff($hoy)->y;
    } catch (Throwable $e) {
        return '—';
    }
}

$edad = edadActual($usuario['fecha_nacimiento'] ?? null);

$meses = [
    'enero', 'febrero', 'marzo', 'abril',
    'mayo', 'junio', 'julio', 'agosto',
    'septiembre', 'octubre', 'noviembre', 'diciembre'
];

$fechaCumple = 'Sin fecha';

if (!empty($usuario['fecha_nacimiento'])) {
    $partesFecha = explode('-', $usuario['fecha_nacimiento']);

    if (
        count($partesFecha) === 3 &&
        (int)$partesFecha[1] >= 1 &&
        (int)$partesFecha[1] <= 12
    ) {
        $fechaCumple =
            (int)$partesFecha[2] .
            ' de ' .
            $meses[(int)$partesFecha[1] - 1];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mi perfil · ExperiMatch</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">

<link rel="stylesheet" href="style.css?v=exp20261104">
</head>

<body class="profile-page">

<div class="bg-orb orb-one"></div>
<div class="bg-orb orb-two"></div>
<div class="bg-orb orb-three"></div>

<div class="sparkles" aria-hidden="true">
    <span></span><span></span><span></span><span></span>
    <span></span><span></span><span></span><span></span>
</div>

<header class="navbar">
    <a href="index.php" class="brand">
        <span class="brand-mark">♥</span>
        <span>ExperiMatch</span>
    </a>

    <nav class="main-nav">
        <a href="index.php">Inicio</a>
        <a href="index.php#explorar">Explorar</a>
        <a href="perfil.php#favoritos" class="active">
            Favoritos
            <b class="nav-badge"><?= $totalFavoritos ?></b>
        </a>
        <a href="perfil.php" class="active">Mi perfil</a>
    </nav>

    <div class="nav-actions">
        <button class="theme-button" id="themeButton" type="button">☾</button>
        <a class="logout-mini" href="login.php?accion=salir">Salir</a>
    </div>
</header>

<main class="profile-main">

<?php if ($mensaje || $error): ?>
    <div class="profile-alert <?= $error ? 'is-error' : '' ?>">
        <?= e($error ?: $mensaje) ?>
    </div>
<?php endif; ?>

<section class="profile-hero-card">

    <div class="profile-identity">

        <div class="profile-avatar-wrap">
            <div class="profile-avatar-large">
                <?php if ($foto): ?>
                    <img
                        src="<?= e($foto) ?>"
                        alt="Foto de <?= e($usuario['nombre_completo']) ?>"
                    >
                <?php else: ?>
                    <span><?= e($inicial) ?></span>
                <?php endif; ?>
            </div>

            <label class="camera-button" for="fotoPerfil">📷</label>
        </div>

        <div class="profile-copy">
            <span class="eyebrow">MI ESPACIO EN EXPERIMATCH</span>

            <h1>
                Hola,
                <?= e(explode(' ', trim($usuario['nombre_completo']))[0] ?? $usuario['nombre_completo']) ?>. ✦
            </h1>

            <p><?= e($usuario['correo']) ?></p>

            <div class="birthday-chip">
                🎂 Mi cumpleaños:
                <b><?= e($fechaCumple) ?></b>
            </div>

            <div class="profile-actions">
                <button
                    type="button"
                    class="btn btn-soft"
                    id="editButton"
                >
                    ✎ Editar perfil
                </button>

                <label for="fotoPerfil" class="btn btn-main">
                    📷 Cambiar foto
                </label>
            </div>
        </div>

    </div>

    <form
        method="post"
        enctype="multipart/form-data"
        class="hidden-form"
    >
        <input type="hidden" name="accion" value="foto">

        <input
            id="fotoPerfil"
            type="file"
            name="foto"
            accept="image/jpeg,image/png,image/webp"
            onchange="this.form.submit()"
        >
    </form>

    <div class="profile-highlight">
        <span>EXPERIENCIAS VISITADAS</span>
        <strong><?= $experienciasDistintas ?></strong>
        <small>
            <?= $totalVisitas ?>
            registro<?= $totalVisitas === 1 ? '' : 's' ?> de visita
        </small>
    </div>

</section>

<section class="profile-stats">

    <article>
        <span>♥</span>
        <div>
            <strong><?= $totalFavoritos ?></strong>
            <small>Favoritos</small>
        </div>
    </article>

    <article>
        <span>⌖</span>
        <div>
            <strong><?= $totalVisitas ?></strong>
            <small>Visitas registradas</small>
        </div>
    </article>

    <article>
        <span>★</span>
        <div>
            <strong><?= $totalResenas ?></strong>
            <small>Reseñas</small>
        </div>
    </article>

    <article>
        <span>🎂</span>
        <div>
            <strong><?= e($edad) ?></strong>
            <small>Años</small>
        </div>
    </article>

</section>

<section class="profile-section" id="favoritos">

    <div class="profile-section-head">
        <div>
            <span class="eyebrow">LO QUE TE GUSTÓ</span>
            <h2>
                Mis lugares favoritos
                <em>(<?= $totalFavoritos ?>)</em>
            </h2>
        </div>

        <a href="index.php#explorar" class="ghost-link">
            Descubrir más →
        </a>
    </div>

    <?php if (!$favoritos): ?>

        <div class="profile-empty">
            <div>♥</div>
            <h3>Todavía no has guardado lugares.</h3>
            <p>
                Explora experiencias y toca el corazón
                para verlas aquí.
            </p>
            <a href="index.php#explorar" class="btn btn-main">
                Explorar ahora →
            </a>
        </div>

    <?php else: ?>

        <div class="profile-favorites-grid">

            <?php foreach ($favoritos as $fav): ?>

                <article class="profile-place-card">

                    <a
                        href="index.php?lugar=<?= (int)$fav['id_lugares'] ?>#detalle"
                        class="profile-place-image"
                    >
                        <img
                            src="<?= e(imagenLugar($fav['nombre_lugar'], $fav['nombre_ciudad'], $fav['tipo_lugar'])) ?>"
                            alt="<?= e($fav['nombre_lugar']) ?>"
                            data-fallback="<?= e(fallbackImagen($fav['tipo_lugar'])) ?>"
                            onerror="imageFallback(this)"
                        >

                        <span><?= e($fav['tipo_lugar']) ?></span>
                    </a>

                    <div class="profile-place-body">

                        <small>
                            📍 <?= e($fav['nombre_ciudad']) ?>
                        </small>

                        <h3><?= e($fav['nombre_lugar']) ?></h3>

                        <p>
                            <?= e(mb_strimwidth(
                                $fav['descripcion'],
                                0,
                                120,
                                '...',
                                'UTF-8'
                            )) ?>
                        </p>

                        <div class="profile-place-footer">

                            <strong>
                                <?= e($fav['precio_corto']) ?>
                            </strong>

                            <form method="post">
                                <input
                                    type="hidden"
                                    name="accion"
                                    value="quitar_favorito"
                                >

                                <input
                                    type="hidden"
                                    name="id_lugar"
                                    value="<?= (int)$fav['id_lugares'] ?>"
                                >

                                <button type="submit">♥ Quitar</button>
                            </form>

                        </div>
                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

<section class="profile-section" id="visitas">

    <div class="profile-section-head">
        <div>
            <span class="eyebrow">TU HISTORIAL</span>
            <h2>Mis experiencias</h2>
        </div>

        <span class="count-result">
            <?= $totalVisitas ?> visitas
        </span>
    </div>

    <?php if (!$visitas): ?>

        <div class="profile-empty small">
            <div>⌖</div>
            <h3>Aún no hay visitas.</h3>
            <p>
                Cuando marques un lugar como visitado,
                aparecerá aquí.
            </p>
        </div>

    <?php else: ?>

        <div class="visit-list">

            <?php foreach ($visitas as $v): ?>

                <a
                    class="visit-row"
                    href="index.php?lugar=<?= (int)$v['id_lugares'] ?>#detalle"
                >

                    <div class="visit-thumb">
                        <img
                            src="<?= e(imagenLugar($v['nombre_lugar'], $v['nombre_ciudad'], $v['tipo_lugar'])) ?>"
                            alt="<?= e($v['nombre_lugar']) ?>"
                            data-fallback="<?= e(fallbackImagen($v['tipo_lugar'])) ?>"
                            onerror="imageFallback(this)"
                        >
                    </div>

                    <div class="visit-info">

                        <span>
                            <?= e($v['tipo_lugar']) ?>
                            · 📍 <?= e($v['nombre_ciudad']) ?>
                        </span>

                        <h3><?= e($v['nombre_lugar']) ?></h3>

                        <p>
                            Última visita: <?= e($v['ultima_visita']) ?>
                            · <?= (int)$v['veces'] ?>
                            registro<?= (int)$v['veces'] === 1 ? '' : 's' ?>
                        </p>

                    </div>

                    <b>→</b>

                </a>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

<section class="profile-quote">
    <span>✦</span>

    <div>
        <strong>
            Tu mapa se construye con cada experiencia.
        </strong>

        <p>
            Guarda lo que te encanta, vuelve a lo que recuerdas
            y descubre lo que todavía te falta.
        </p>
    </div>
</section>

</main>

<div class="edit-modal" id="editModal">

    <div class="edit-card">

        <button
            id="closeEdit"
            class="edit-close"
            type="button"
        >×</button>

        <span class="eyebrow">EDITAR PERFIL</span>

        <h2>
            Hazlo tan tuyo como tus lugares favoritos.
        </h2>

        <form method="post">

            <input
                type="hidden"
                name="accion"
                value="editar"
            >

            <label>
                Nombre completo
                <input
                    type="text"
                    name="nombre"
                    value="<?= e($usuario['nombre_completo']) ?>"
                    required
                >
            </label>

            <label>
                Fecha de nacimiento
                <input
                    type="date"
                    name="fecha"
                    value="<?= e($usuario['fecha_nacimiento'] ?? '') ?>"
                    required
                >
            </label>

            <label>
                Correo
                <input
                    type="email"
                    name="correo"
                    value="<?= e($usuario['correo']) ?>"
                    required
                >
            </label>

            <button class="btn btn-main full" type="submit">
                Guardar cambios →
            </button>

        </form>

    </div>

</div>

<script>
function imageFallback(img) {
    if (img.dataset.used === '1') {
        return;
    }

    img.dataset.used = '1';

    if (img.dataset.fallback) {
        img.src = img.dataset.fallback;
    }
}

const themeButton = document.getElementById('themeButton');

if (localStorage.getItem('experimatch-theme') === 'light') {
    document.body.classList.add('light-theme');
}

function syncTheme() {
    if (themeButton) {
        themeButton.textContent =
            document.body.classList.contains('light-theme')
            ? '☀'
            : '☾';
    }
}

syncTheme();

themeButton?.addEventListener('click', () => {
    document.body.classList.toggle('light-theme');

    localStorage.setItem(
        'experimatch-theme',
        document.body.classList.contains('light-theme')
            ? 'light'
            : 'dark'
    );

    syncTheme();
});

const modal = document.getElementById('editModal');

document.getElementById('editButton')?.addEventListener(
    'click',
    () => modal.classList.add('show')
);

document.getElementById('closeEdit')?.addEventListener(
    'click',
    () => modal.classList.remove('show')
);

modal?.addEventListener('click', (e) => {
    if (e.target === modal) {
        modal.classList.remove('show');
    }
});
</script>

</body>
</html>
