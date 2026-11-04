<?php
// EXPERIMATCH_FIX_VISITAS_2026_11_04
require_once __DIR__ . '/database.php';
$usuario = usuarioActual();
$idUsuario = (int)($usuario['id_usuario'] ?? 0);

/** Registra una visita usando la estructura real de ExperiMatch.
 * id_usuario_lugares es AUTO_INCREMENT, por eso MySQL lo genera solo.
 */
function registrarVisitaExperiMatch(PDO $pdo, int $idLugar, int $idUsuario): void {
    $sql = "INSERT INTO usuario_lugares (id_lugares, id_usuario, fecha_visita)
            VALUES (?, ?, CURDATE())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$idLugar, $idUsuario]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $idLugar = (int)($_POST['id_lugar'] ?? 0);
    if ($accion === 'favorito') {
        if (!$usuario) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'login' => true]);
            exit;
        }
        if (esFavorito($idLugar)) {
            $stmt = $pdo->prepare('DELETE FROM favoritos WHERE id_usuario = ? AND id_lugares = ?');
            $stmt->execute([$idUsuario, $idLugar]);
            $guardado = false;
        } else {
            $stmt = $pdo->prepare('INSERT IGNORE INTO favoritos (id_usuario, id_lugares) VALUES (?, ?)');
            $stmt->execute([$idUsuario, $idLugar]);
            $guardado = true;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM favoritos WHERE id_usuario = ?');
        $stmt->execute([$idUsuario]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'guardado' => $guardado, 'total' => (int)$stmt->fetchColumn()]);
        exit;
    }
    if ($accion === 'visita') {
        requiereLogin();
        $stmt = $pdo->prepare('SELECT 1 FROM usuario_lugares WHERE id_usuario = ? AND id_lugares = ? AND fecha_visita = CURDATE() LIMIT 1');
        $stmt->execute([$idUsuario, $idLugar]);
        if (!$stmt->fetchColumn()) {
            registrarVisitaExperiMatch($pdo, $idLugar, $idUsuario);
        }
        header('Location: index.php?lugar=' . $idLugar . '#detalle');
        exit;
    }
    if ($accion === 'resena') {
        requiereLogin();
        $calificacion = (int)($_POST['calificacion'] ?? 0);
        $comentario = trim($_POST['comentario'] ?? '');
        $stmt = $pdo->prepare('SELECT 1 FROM usuario_lugares WHERE id_usuario = ? AND id_lugares = ? LIMIT 1');
        $stmt->execute([$idUsuario, $idLugar]);
        $visito = (bool)$stmt->fetchColumn();
        if ($visito && $calificacion >= 1 && $calificacion <= 5 && $comentario !== '') {
            $stmt = $pdo->prepare('INSERT INTO resena (fecha, calificacion, comentario, id_lugares, id_usuario) VALUES (CURDATE(), ?, ?, ?, ?)');
            $stmt->execute([$calificacion, $comentario, $idLugar, $idUsuario]);
        }
        header('Location: index.php?lugar=' . $idLugar . '#detalle');
        exit;
    }
}
$q = trim($_GET['q'] ?? '');
$ciudad = (int)($_GET['ciudad'] ?? 0);
$tipo = (int)($_GET['tipo'] ?? 0);
$etiqueta = (int)($_GET['etiqueta'] ?? 0);
$presupuesto = $_GET['presupuesto'] ?? '';
$solo = $_GET['solo'] ?? '';
$ciudades = $pdo->query('SELECT id_ciudad, nombre_ciudad FROM ciudad ORDER BY nombre_ciudad')->fetchAll();
$tipos = $pdo->query('SELECT id_tipo_lugar, tipo_lugar FROM tipo_lugar ORDER BY tipo_lugar')->fetchAll();
$etiquetas = $pdo->query('SELECT e.id_etiqueta, e.nombre_etiqueta, COUNT(DISTINCT le.id_lugar) AS total_lugares FROM etiqueta e LEFT JOIN lugar_etiqueta le ON le.id_etiqueta = e.id_etiqueta GROUP BY e.id_etiqueta, e.nombre_etiqueta ORDER BY total_lugares DESC, e.nombre_etiqueta')->fetchAll();
$sql = "SELECT l.id_lugares, l.nombre_lugar, l.descripcion, l.calle, l.carrera, l.numero, c.nombre_ciudad, tl.tipo_lugar, em.edad,
    (SELECT COALESCE(ROUND(AVG(r.calificacion),1),0) FROM resena r WHERE r.id_lugares=l.id_lugares) AS promedio,
    (SELECT COUNT(*) FROM resena r2 WHERE r2.id_lugares=l.id_lugares) AS total_resenas,
    (SELECT COUNT(*) FROM usuario_lugares uv WHERE uv.id_lugares=l.id_lugares) AS total_visitas,
    COALESCE((SELECT GROUP_CONCAT(DISTINCT e.nombre_etiqueta ORDER BY e.nombre_etiqueta SEPARATOR '||') FROM lugar_etiqueta le2 INNER JOIN etiqueta e ON e.id_etiqueta=le2.id_etiqueta WHERE le2.id_lugar=l.id_lugares),'') AS etiquetas
    FROM lugares l
    INNER JOIN ciudad c ON c.id_ciudad=l.id_ciudad
    INNER JOIN tipo_lugar tl ON tl.id_tipo_lugar=l.id_tipo_lugar
    LEFT JOIN edad_minima em ON em.id_edad_minima=l.id_edad_minima
    WHERE 1=1";
$params = [];
if ($q !== '') {
    $sql .= " AND (l.nombre_lugar LIKE ? OR l.descripcion LIKE ? OR c.nombre_ciudad LIKE ? OR tl.tipo_lugar LIKE ? OR EXISTS (SELECT 1 FROM lugar_etiqueta leq INNER JOIN etiqueta eq ON eq.id_etiqueta=leq.id_etiqueta WHERE leq.id_lugar=l.id_lugares AND eq.nombre_etiqueta LIKE ?))";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($ciudad > 0) {
    $sql .= ' AND l.id_ciudad = ?';
    $params[] = $ciudad;
}
if ($tipo > 0) {
    $sql .= ' AND l.id_tipo_lugar = ?';
    $params[] = $tipo;
}
if ($etiqueta > 0) {
    $sql .= ' AND EXISTS (SELECT 1 FROM lugar_etiqueta letq WHERE letq.id_lugar=l.id_lugares AND letq.id_etiqueta=?)';
    $params[] = $etiqueta;
}
if ($solo === 'favoritos' && $usuario) {
    $sql .= ' AND EXISTS (SELECT 1 FROM favoritos f WHERE f.id_lugares=l.id_lugares AND f.id_usuario=?)';
    $params[] = $idUsuario;
}
$sql .= ' ORDER BY total_visitas DESC, promedio DESC, l.nombre_lugar ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lugares = $stmt->fetchAll();
foreach ($lugares as &$lugar) {
    $precio = precioLugar($lugar['nombre_lugar'], $lugar['tipo_lugar']);
    $lugar['precio_nivel'] = $precio['nivel'];
    $lugar['precio_corto'] = $precio['corto'];
    $lugar['precio_detalle'] = $precio['detalle'];
    $lugar['presupuesto_real'] = presupuestoTexto($precio['nivel']);
}
unset($lugar);
if ($presupuesto !== '') {
    $permitidos = array_filter(array_map('intval', explode(',', $presupuesto)));
    $lugares = array_values(array_filter($lugares, fn($l) => in_array((int)$l['precio_nivel'], $permitidos, true)));
}
$destacado = $pdo->query("SELECT l.id_lugares, l.nombre_lugar, l.descripcion, c.nombre_ciudad, tl.tipo_lugar,
    (SELECT COUNT(*) FROM usuario_lugares uv WHERE uv.id_lugares=l.id_lugares) AS total_visitas,
    (SELECT COALESCE(ROUND(AVG(r.calificacion),1),0) FROM resena r WHERE r.id_lugares=l.id_lugares) AS promedio
    FROM lugares l
    INNER JOIN ciudad c ON c.id_ciudad=l.id_ciudad
    INNER JOIN tipo_lugar tl ON tl.id_tipo_lugar=l.id_tipo_lugar
    ORDER BY total_visitas DESC, promedio DESC, l.id_lugares ASC
    LIMIT 1")->fetch();
$detalle = null;
$detalleTags = [];
$detalleResenas = [];
if (isset($_GET['lugar'])) {
    $idDetalle = (int)$_GET['lugar'];
    $stmt = $pdo->prepare("SELECT l.*, c.nombre_ciudad, tl.tipo_lugar, em.edad,
        (SELECT COALESCE(ROUND(AVG(r.calificacion),1),0) FROM resena r WHERE r.id_lugares=l.id_lugares) AS promedio,
        (SELECT COUNT(*) FROM resena r2 WHERE r2.id_lugares=l.id_lugares) AS total_resenas,
        (SELECT COUNT(*) FROM usuario_lugares uv WHERE uv.id_lugares=l.id_lugares) AS total_visitas
        FROM lugares l
        INNER JOIN ciudad c ON c.id_ciudad=l.id_ciudad
        INNER JOIN tipo_lugar tl ON tl.id_tipo_lugar=l.id_tipo_lugar
        LEFT JOIN edad_minima em ON em.id_edad_minima=l.id_edad_minima
        WHERE l.id_lugares=? LIMIT 1");
    $stmt->execute([$idDetalle]);
    $detalle = $stmt->fetch();
    if ($detalle) {
        $p = precioLugar($detalle['nombre_lugar'], $detalle['tipo_lugar']);
        $detalle['precio_corto'] = $p['corto'];
        $detalle['precio_detalle'] = $p['detalle'];
        $detalle['presupuesto_real'] = presupuestoTexto($p['nivel']);
        $stmt = $pdo->prepare('SELECT e.id_etiqueta, e.nombre_etiqueta FROM lugar_etiqueta le INNER JOIN etiqueta e ON e.id_etiqueta=le.id_etiqueta WHERE le.id_lugar=? ORDER BY e.nombre_etiqueta');
        $stmt->execute([$idDetalle]);
        $detalleTags = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT r.fecha, r.calificacion, r.comentario, u.nombre_completo FROM resena r INNER JOIN usuario u ON u.id_usuario=r.id_usuario WHERE r.id_lugares=? ORDER BY r.fecha DESC, r.id_resena DESC');
        $stmt->execute([$idDetalle]);
        $detalleResenas = $stmt->fetchAll();
    }
}
$favoritosIds = [];
$totalFavoritos = 0;
$totalVisitasUsuario = 0;
$totalResenasUsuario = 0;
if ($usuario) {
    $stmt = $pdo->prepare('SELECT id_lugares FROM favoritos WHERE id_usuario=?');
    $stmt->execute([$idUsuario]);
    $favoritosIds = array_map('intval', array_column($stmt->fetchAll(), 'id_lugares'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM favoritos WHERE id_usuario=?');
    $stmt->execute([$idUsuario]);
    $totalFavoritos = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuario_lugares WHERE id_usuario=?');
    $stmt->execute([$idUsuario]);
    $totalVisitasUsuario = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM resena WHERE id_usuario=?');
    $stmt->execute([$idUsuario]);
    $totalResenasUsuario = (int)$stmt->fetchColumn();
}
function esFavLocal(array $ids, int $id): bool {
    return in_array($id, $ids, true);
}
function listaEtiquetas(string $cadena): array {
    return $cadena === '' ? [] : array_filter(explode('||', $cadena));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ExperiMatch · Tu próximo plan</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=exp20261104-visual2">
</head>
<body>
<div class="bg-orb orb-one"></div>
<div class="bg-orb orb-two"></div>
<div class="bg-orb orb-three"></div>
<div class="sparkles" aria-hidden="true">
<span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
</div>
<header class="navbar">
<a href="index.php" class="brand"><span class="brand-mark" aria-hidden="true"><span class="brand-pin">✦</span></span><span class="brand-name">ExperiMatch</span></a>
<nav class="main-nav">
<a href="index.php" class="active">Inicio</a>
<a href="index.php#explorar">Explorar</a>
<a href="index.php#sugerir-lugar">Sugerir un lugar</a>
<a href="<?= $usuario ? 'perfil.php' : 'login.php?modo=login&mensaje=favoritos' ?>">Favoritos <b class="nav-badge favorite-count"><?= $totalFavoritos ?></b></a>
<a href="<?= $usuario ? 'perfil.php' : 'login.php?modo=login&mensaje=perfil' ?>">Mi perfil</a>
</nav>
<div class="nav-actions">
<button class="theme-button" type="button" id="themeButton">☾</button>
<?php if ($usuario): ?>
<a class="profile-mini" href="perfil.php"><span><?= e(mb_strtoupper(mb_substr($usuario['nombre_completo'],0,1,'UTF-8'),'UTF-8')) ?></span><?= e(explode(' ',$usuario['nombre_completo'])[0]) ?></a>
<a class="logout-mini" href="login.php?accion=salir">Salir</a>
<?php else: ?>
<a class="login-mini" href="login.php?modo=login">Iniciar sesión</a>
<a class="btn btn-small" href="login.php?modo=registro">Crear cuenta</a>
<?php endif; ?>
</div>
</header>
<main>
<section class="hero-section">
<div class="hero-copy">
<span class="eyebrow">DESCUBRE · CONECTA · DISFRUTA ✦</span>
<h1>Tu próxima <span>experiencia</span><br>puede estar aquí.</h1>
<p>Explora lugares que combinan con tu estilo, tu ciudad y el presupuesto que tienes en mente.</p>
<div class="hero-buttons">
<a href="#explorar" class="btn btn-main">Explorar lugares <span>↗</span></a>
<a href="#etiquetas" class="ghost-link">Buscar por vibra →</a>
</div>
<div class="hero-proof"><span><strong><?= count($lugares) ?></strong> experiencias</span><span><strong><?= count($etiquetas) ?></strong> etiquetas</span><span><strong><?= count($ciudades) ?></strong> ciudades</span></div>
</div>
<?php if ($destacado): ?>
<div class="featured-area">
<div class="featured-label">PASA EL CURSOR · GIRA LA TARJETA ↻</div>
<div class="flip-card" tabindex="0" aria-label="Tarjeta de experiencia destacada">
<div class="flip-inner">
<div class="flip-face flip-front">
<div class="front-glow"></div>
<div class="front-icon">✦</div>
<span>EXPERIMATCH TE PREGUNTA</span>
<h2>¿Qué lugar<br>te falta por descubrir?</h2>
<p>Gira la tarjeta y conoce la experiencia con más visitas registradas.</p>
<div class="front-bottom"><small>La base de datos decide ✦</small><b>↻</b></div>
</div>
<div class="flip-face flip-back">
<div class="featured-image">
<img src="<?= e(imagenLugar($destacado['nombre_lugar'],$destacado['nombre_ciudad'],$destacado['tipo_lugar'])) ?>" alt="<?= e($destacado['nombre_lugar']) ?>" data-fallback="<?= e(fallbackImagen($destacado['tipo_lugar'])) ?>" onerror="imageFallback(this)">
<div class="image-overlay"></div>
<div class="most-visited">🔥 MÁS VISITADO</div>
</div>
<div class="back-content">
<span class="eyebrow">EL DESTACADO DE HOY</span>
<h2><?= e($destacado['nombre_lugar']) ?></h2>
<p>📍 <?= e($destacado['nombre_ciudad']) ?></p>
<div class="back-stats"><div><b><?= (int)$destacado['total_visitas'] ?></b><span>visitas</span></div><div><b>★ <?= $destacado['promedio'] ?: 'Nuevo' ?></b><span>calificación</span></div></div>
<a href="index.php?lugar=<?= (int)$destacado['id_lugares'] ?>#detalle" class="btn btn-light">Ver experiencia →</a>
</div>
</div>
</div>
</div>
</div>
<?php endif; ?>
</section>
<section class="vibe-section" id="etiquetas">
<div class="section-top"><div><span class="eyebrow">ELIGE POR VIBRA</span><h2>Encuentra la etiqueta que te representa.</h2></div><p>Escribe una palabra, filtra y descubre planes relacionados.</p></div>
<div class="vibe-search"><span>⌕</span><input id="tagSearch" type="search" placeholder="Busca una etiqueta..." autocomplete="off"></div>
<div class="tag-cloud" id="tagCloud">
<a href="index.php#explorar" class="tag-chip tag-active" data-tag="todas">Todas <b><?= count($lugares) ?></b></a>
<?php foreach ($etiquetas as $tag): ?>
<a href="index.php?etiqueta=<?= (int)$tag['id_etiqueta'] ?>#explorar" class="tag-chip" data-tag="<?= e(mb_strtolower($tag['nombre_etiqueta'],'UTF-8')) ?>">#<?= e($tag['nombre_etiqueta']) ?> <b><?= (int)$tag['total_lugares'] ?></b></a>
<?php endforeach; ?>
</div>
</section>
<section class="explore-section" id="explorar">
<div class="section-top"><div><span class="eyebrow">EXPLORA SIN COMPLICARTE</span><h2>Tu próximo lugar empieza aquí.</h2><p>Busca por nombre, ciudad, tipo, presupuesto o etiqueta.</p></div><span class="count-result"><?= count($lugares) ?> lugares</span></div>
<form method="get" class="search-panel">
<div class="search-input-wrap"><span>⌕</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Busca lugares, ciudades, tipos o etiquetas..."></div>
<select name="ciudad"><option value="0">Todas las ciudades</option><?php foreach ($ciudades as $c): ?><option value="<?= (int)$c['id_ciudad'] ?>" <?= $ciudad === (int)$c['id_ciudad'] ? 'selected' : '' ?>><?= e($c['nombre_ciudad']) ?></option><?php endforeach; ?></select>
<select name="tipo"><option value="0">Todos los tipos</option><?php foreach ($tipos as $t): ?><option value="<?= (int)$t['id_tipo_lugar'] ?>" <?= $tipo === (int)$t['id_tipo_lugar'] ? 'selected' : '' ?>><?= e($t['tipo_lugar']) ?></option><?php endforeach; ?></select>
<select name="presupuesto"><option value="">Cualquier presupuesto</option><option value="1">$ Económico</option><option value="2">$$ Medio</option><option value="3">$$$ Alto</option><option value="4">$$$$ Premium</option></select>
<button class="btn btn-main" type="submit">Buscar</button>
</form>
<?php if ($q || $ciudad || $tipo || $etiqueta || $presupuesto): ?><div class="active-filters"><span>Filtros activos</span><?php if ($q): ?><b>“<?= e($q) ?>”</b><?php endif; ?><?php if ($ciudad): ?><b>Ciudad</b><?php endif; ?><?php if ($tipo): ?><b>Tipo</b><?php endif; ?><?php if ($etiqueta): ?><b>Etiqueta</b><?php endif; ?><?php if ($presupuesto): ?><b>Presupuesto</b><?php endif; ?><a href="index.php#explorar">Limpiar ×</a></div><?php endif; ?>
</section>
<section class="places-section">
<?php if (!$lugares): ?>
<div class="empty-state"><div class="empty-star">✦</div><h3>No encontramos ese plan.</h3><p>Prueba con otra palabra o combina menos filtros.</p><a href="index.php#explorar" class="btn btn-main">Ver todo</a></div>
<?php else: ?>
<div class="places-grid">
<?php foreach ($lugares as $lugar): ?>
<?php $lid=(int)$lugar['id_lugares']; $fav=esFavLocal($favoritosIds,$lid); $tagsLugar=listaEtiquetas($lugar['etiquetas']); ?>
<article class="place-card <?= $fav ? 'is-favorite' : '' ?>">
<a class="place-image" href="index.php?lugar=<?= $lid ?>#detalle"><img src="<?= e(imagenLugar($lugar['nombre_lugar'],$lugar['nombre_ciudad'],$lugar['tipo_lugar'])) ?>" alt="<?= e($lugar['nombre_lugar']) ?>" loading="lazy" data-fallback="<?= e(fallbackImagen($lugar['tipo_lugar'])) ?>" onerror="imageFallback(this)"><span><?= e($lugar['tipo_lugar']) ?></span></a>
<button class="heart-button <?= $fav ? 'saved' : '' ?>" type="button" data-favorite="<?= $lid ?>" aria-label="Guardar <?= e($lugar['nombre_lugar']) ?>"><?= $fav ? '♥' : '♡' ?></button>
<div class="place-content"><div class="place-location">📍 <?= e($lugar['nombre_ciudad']) ?></div><h3><a href="index.php?lugar=<?= $lid ?>#detalle"><?= e($lugar['nombre_lugar']) ?></a></h3><p><?= e(mb_strimwidth($lugar['descripcion'],0,145,'...','UTF-8')) ?></p><div class="place-tags"><?php foreach (array_slice($tagsLugar,0,3) as $tag): ?><span>#<?= e($tag) ?></span><?php endforeach; ?></div><div class="place-meta"><span>★ <?= $lugar['promedio'] ?: 'Nuevo' ?></span><span>🔥 <?= (int)$lugar['total_visitas'] ?></span><span><?= e($lugar['precio_corto']) ?></span></div><a class="view-link" href="index.php?lugar=<?= $lid ?>#detalle">Ver experiencia <b>→</b></a></div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
<section class="why-section"><div class="why-card"><div><span class="eyebrow">HECHO PARA DESCUBRIR</span><h2>No solo busques un lugar. Encuentra un plan que te dé ganas de ir.</h2><p>Guarda tus favoritos, registra tus visitas y construye tu propio mapa de experiencias.</p></div><a href="<?= $usuario ? 'perfil.php' : 'login.php?modo=registro' ?>" class="btn btn-main"><?= $usuario ? 'Ver mi perfil →' : 'Crear mi espacio →' ?></a></div></section>
<section class="community-section" id="sugerir-lugar">
    <div class="community-aura community-aura-one"></div>
    <div class="community-aura community-aura-two"></div>
    <div class="community-copy">
        <span class="eyebrow">TUS IDEAS TAMBIÉN HACEN CRECER EXPERIMATCH ✦</span>
        <h2>¿Tienes una sugerencia para descubrir nuevos lugares?</h2>
        <p class="community-lead">En ExperiMatch creemos que las mejores recomendaciones nacen de quienes conocen cada rincón. Recomiéndanos un restaurante, parque, museo, actividad o rincón especial. Tu sugerencia puede convertirse en la próxima experiencia de nuestra comunidad.</p>
        <div class="community-mail-card">
            <span class="community-mail-icon">✉</span>
            <div><small>ENVÍA TU PROPUESTA A</small><strong>experimatchmilugar@gmail.com</strong></div>
            <span class="community-mail-spark">✦</span>
        </div>
        <a class="community-cta" href="mailto:experimatchmilugar@gmail.com?subject=Propuesta%20de%20lugar%20para%20ExperiMatch&body=Hola%20equipo%20ExperiMatch%3A%0AQuiero%20recomendar%20un%20lugar%20o%20experiencia.%0A%0ANOMBRE%20DEL%20LUGAR%3A%0ACIUDAD%20Y%20PAIS%3A%0ADIRECCION%20O%20UBICACION%20EXACTA%3A%0ACATEGORIA%3A%0ADESCRIPCION%3A%0APRESUPUESTO%20O%20PRECIO%3A%0AEDAD%20RECOMENDADA%20O%20RESTRICCIONES%3A%0AHORARIOS%20Y%20DIAS%3A%0AWEB%20O%20REDES%20SOCIALES%3A%0AENLACE%20A%20FOTOGRAFIAS%3A%0A%C2%BFPOR%20QUE%20LO%20RECOMIENDO%3F%3A%0A%0AGracias%20por%20hacer%20crecer%20ExperiMatch.">
            Enviar mi sugerencia <span>↗</span>
        </a>
        <p class="community-note">El botón abre tu aplicación de correo con una guía lista para completar. Nuestro equipo revisará cada propuesta antes de publicarla.</p>
    </div>
    <div class="community-visual">
        <div class="community-orbit orbit-a"></div>
        <div class="community-orbit orbit-b"></div>
        <div class="community-globe"><span>✦</span></div>
        <span class="community-float float-pin">📍</span>
        <span class="community-float float-star">✧</span>
        <span class="community-float float-heart">♡</span>
        <div class="community-visual-caption"><strong>Un lugar. Una historia. Un nuevo destino.</strong><span>Tu recomendación puede inspirar el próximo viaje.</span></div>
    </div>
    <details class="community-guide">
        <summary><span>✧</span> ¿Qué información debo enviar?</summary>
        <div class="community-guide-content">
            <p>Para que podamos conocer y evaluar tu propuesta, incluye:</p>
            <ul>
                <li><b>Nombre del lugar</b> y categoría (restaurante, parque, museo, actividad, etc.).</li>
                <li><b>Ciudad y país</b>, dirección exacta o enlace de ubicación en el mapa.</li>
                <li><b>Descripción</b> de lo que ofrece y qué lo hace especial.</li>
                <li><b>Precio o presupuesto aproximado</b>, indicando si la entrada es gratuita.</li>
                <li><b>Edad recomendada</b> y restricciones de acceso, si existen.</li>
                <li><b>Horarios</b>, días de atención y temporadas de funcionamiento.</li>
                <li><b>Página web o redes sociales</b> y fotografías propias o enlaces autorizados.</li>
                <li><b>Tu recomendación:</b> cuéntanos por qué merece estar en ExperiMatch.</li>
            </ul>
        </div>
    </details>
</section>
</main>
<?php if ($detalle): ?>
<?php $direccion=trim(($detalle['calle']??'').' '.($detalle['carrera']??'').' '.($detalle['numero']??'')); $mapQuery=trim($detalle['nombre_lugar'].' '.$direccion.' '.$detalle['nombre_ciudad']); $detalleFav=esFavorito((int)$detalle['id_lugares']); ?>
<div class="detail-overlay" id="detalle"><div class="detail-modal"><a href="index.php#explorar" class="detail-close">×</a><div class="detail-top-image"><img src="<?= e(imagenLugar($detalle['nombre_lugar'],$detalle['nombre_ciudad'],$detalle['tipo_lugar'])) ?>" alt="<?= e($detalle['nombre_lugar']) ?>" data-fallback="<?= e(fallbackImagen($detalle['tipo_lugar'])) ?>" onerror="imageFallback(this)"><div class="detail-shade"></div><div class="detail-title"><span><?= e($detalle['tipo_lugar']) ?></span><h1><?= e($detalle['nombre_lugar']) ?></h1><p>📍 <?= e($detalle['nombre_ciudad']) ?></p></div><div class="detail-visit-pill">🔥 <?= (int)$detalle['total_visitas'] ?> visitas</div></div><div class="detail-body"><div class="detail-main"><span class="eyebrow">LA EXPERIENCIA</span><p class="detail-description"><?= nl2br(e($detalle['descripcion'])) ?></p><div class="detail-stats"><div><small>VISITAS</small><strong><?= (int)$detalle['total_visitas'] ?></strong></div><div><small>CALIFICACIÓN</small><strong>★ <?= $detalle['promedio'] ?: 'Nuevo' ?></strong></div><div><small>PRECIO</small><strong><?= e($detalle['precio_corto']) ?></strong></div><div><small>EDAD</small><strong><?= $detalle['edad'] !== null ? e($detalle['edad']).'+' : 'Todos' ?></strong></div></div><div class="real-price"><div><span>💸 REFERENCIA DE PRECIO</span><strong><?= e($detalle['precio_corto']) ?></strong><p><?= e($detalle['precio_detalle']) ?></p><small><?= e($detalle['precio_corto'] === 'Gratis' ? 'Precio informado como gratuito.' : 'Confirma el valor antes de salir, especialmente en eventos o temporadas especiales.') ?></small></div><b><?= e($detalle['presupuesto_real']) ?></b></div><div class="address-card"><div><span>DIRECCIÓN</span><strong><?= e($direccion ?: 'Dirección no registrada') ?></strong></div><a class="btn btn-map" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=<?= rawurlencode($mapQuery) ?>">⌖ Cómo llegar</a></div><div class="detail-tags"><span>ETIQUETAS</span><div><?php foreach ($detalleTags as $tag): ?><a href="index.php?etiqueta=<?= (int)$tag['id_etiqueta'] ?>#explorar">#<?= e($tag['nombre_etiqueta']) ?></a><?php endforeach; ?></div></div><div class="detail-actions"><button class="btn btn-main favorite-detail" type="button" data-favorite="<?= (int)$detalle['id_lugares'] ?>"><?= $detalleFav ? '♥ Guardado' : '♡ Guardar favorito' ?></button><?php if ($usuario): ?><form method="post"><input type="hidden" name="accion" value="visita"><input type="hidden" name="id_lugar" value="<?= (int)$detalle['id_lugares'] ?>"><button class="btn btn-soft" type="submit">✓ Marcar como visitado</button></form><?php else: ?><a class="btn btn-soft" href="login.php?modo=login&mensaje=visita">Inicia sesión para registrar visita</a><?php endif; ?></div></div><aside class="reviews-panel"><div class="review-heading"><span class="eyebrow">COMUNIDAD</span><h3>Opiniones <small><?= (int)$detalle['total_resenas'] ?></small></h3></div><?php if ($usuario): ?><form method="post" class="review-form"><input type="hidden" name="accion" value="resena"><input type="hidden" name="id_lugar" value="<?= (int)$detalle['id_lugares'] ?>"><select name="calificacion"><option value="5">★★★★★ · 5</option><option value="4">★★★★☆ · 4</option><option value="3">★★★☆☆ · 3</option><option value="2">★★☆☆☆ · 2</option><option value="1">★☆☆☆☆ · 1</option></select><textarea name="comentario" placeholder="Cuenta cómo fue tu experiencia..." required maxlength="300"></textarea><button class="btn btn-main" type="submit">Publicar reseña</button></form><?php endif; ?><div class="reviews-list"><?php if (!$detalleResenas): ?><p class="muted">Todavía no hay opiniones.</p><?php else: ?><?php foreach ($detalleResenas as $r): ?><div class="review-item"><div><strong><?= e($r['nombre_completo']) ?></strong><span>★ <?= (int)$r['calificacion'] ?>/5</span></div><p><?= e($r['comentario']) ?></p><small><?= e($r['fecha']) ?></small></div><?php endforeach; ?><?php endif; ?></div></aside></div></div></div>
<?php endif; ?>
<footer><div><strong>ExperiMatch ✦</strong><span>Descubre lugares que hacen match contigo.</span></div><a href="#">Volver arriba ↑</a></footer>
<script>
function imageFallback(img){if(img.dataset.fallbackUsed==='1'){return;}img.dataset.fallbackUsed='1';if(img.dataset.fallback){img.src=img.dataset.fallback;}}
const themeButton=document.getElementById('themeButton');
if(localStorage.getItem('experimatch-theme')==='light'){document.body.classList.add('light-theme');}
function syncTheme(){if(themeButton){themeButton.textContent=document.body.classList.contains('light-theme')?'☀':'☾';}}
syncTheme();
themeButton?.addEventListener('click',()=>{document.body.classList.toggle('light-theme');localStorage.setItem('experimatch-theme',document.body.classList.contains('light-theme')?'light':'dark');syncTheme();});
const flip=document.querySelector('.flip-card');
flip?.addEventListener('click',()=>flip.classList.toggle('is-flipped'));
flip?.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();flip.classList.toggle('is-flipped');}});
const tagSearch=document.getElementById('tagSearch');
tagSearch?.addEventListener('input',()=>{const t=tagSearch.value.toLowerCase().trim();document.querySelectorAll('.tag-chip').forEach(chip=>{const name=(chip.dataset.tag||'').toLowerCase();chip.hidden=t!==''&&name!=='todas'&&!name.includes(t);});});
async function toggleFavorite(button){if(!<?= $usuario ? 'true' : 'false' ?>){location.href='login.php?modo=login&mensaje=favoritos';return;}const id=button.dataset.favorite;button.disabled=true;try{const fd=new FormData();fd.append('accion','favorito');fd.append('id_lugar',id);const r=await fetch('index.php',{method:'POST',body:fd,credentials:'same-origin'});const data=await r.json();if(!data.ok)throw new Error('login');document.querySelectorAll('[data-favorite="'+id+'"]').forEach(el=>{el.classList.toggle('saved',data.guardado);if(el.classList.contains('favorite-detail')){el.textContent=data.guardado?'♥ Guardado':'♡ Guardar favorito';}else{el.textContent=data.guardado?'♥':'♡';}});document.querySelectorAll('.favorite-count').forEach(el=>el.textContent=data.total);}catch(e){alert('No pudimos actualizar el favorito. Comprueba que estés dentro de localhost/ExperiMatch/.');}finally{button.disabled=false;}}
document.querySelectorAll('[data-favorite]').forEach(b=>b.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();toggleFavorite(b);}));
if(location.hash==='#detalle'){document.body.classList.add('modal-open');}
document.querySelector('.detail-overlay')?.addEventListener('click',e=>{if(e.target.classList.contains('detail-overlay'))location.href='index.php#explorar';});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&document.querySelector('.detail-overlay'))location.href='index.php#explorar';});
</script>
</body>
</html>
