<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('ExperiMatchSession');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
$host = '127.0.0.1';
$db = 'experimatch';
$user = 'root';
$pass = '';
try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('No se pudo conectar con la base de datos. Enciende MySQL en XAMPP y verifica que exista la base de datos experimatch.');
}
function columnaExiste(PDO $pdo, string $tabla, string $columna): bool {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tabla}` LIKE ?");
    $stmt->execute([$columna]);
    return (bool)$stmt->fetch();
}
function agregarColumnaSiFalta(PDO $pdo, string $tabla, string $columna, string $definicion): void {
    try {
        if (!columnaExiste($pdo, $tabla, $columna)) {
            $pdo->exec("ALTER TABLE `{$tabla}` ADD COLUMN `{$columna}` {$definicion}");
        }
    } catch (Throwable $e) {
    }
}
function asegurarAutoIncremento(PDO $pdo, string $tabla, string $columna): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tabla}` LIKE '{$columna}'");
        $row = $stmt->fetch();
        if ($row && stripos((string)($row['Extra'] ?? ''), 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE `{$tabla}` MODIFY `{$columna}` INT(11) NOT NULL AUTO_INCREMENT");
        }
    } catch (Throwable $e) {
    }
}
agregarColumnaSiFalta($pdo, 'usuario', 'password_hash', 'VARCHAR(255) NULL');
agregarColumnaSiFalta($pdo, 'usuario', 'foto_perfil', 'VARCHAR(500) NULL');
agregarColumnaSiFalta($pdo, 'lugares', 'presupuesto', "VARCHAR(10) NULL DEFAULT '$$'");
asegurarAutoIncremento($pdo, 'usuario', 'id_usuario');
asegurarAutoIncremento($pdo, 'usuario_lugares', 'usuario_lugares');
asegurarAutoIncremento($pdo, 'resena', 'id_resena');
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS favoritos (
        id_favorito INT(11) NOT NULL AUTO_INCREMENT,
        id_usuario INT(11) NOT NULL,
        id_lugares INT(11) NOT NULL,
        fecha_guardado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_favorito),
        UNIQUE KEY uq_favorito (id_usuario, id_lugares),
        CONSTRAINT fk_fav_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE,
        CONSTRAINT fk_fav_lugar FOREIGN KEY (id_lugares) REFERENCES lugares(id_lugares) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
}
function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function usuarioActual(): ?array {
    global $pdo;
    $id = (int)($_SESSION['id_usuario'] ?? 0);
    if ($id <= 0 && isset($_SESSION['usuario']) && is_array($_SESSION['usuario'])) {
        $id = (int)($_SESSION['usuario']['id_usuario'] ?? 0);
    }
    if ($id <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT u.*, td.Tipo_Documento, g.tipo_genero FROM usuario u LEFT JOIN tipo_documento td ON td.id_tipo_documento = u.id_tipo_documento LEFT JOIN genero g ON g.id_genero = u.id_genero WHERE u.id_usuario = ? LIMIT 1");
        $stmt->execute([$id]);
        $usuario = $stmt->fetch();
        if (!$usuario) {
            unset($_SESSION['usuario'], $_SESSION['id_usuario'], $_SESSION['nombre_usuario'], $_SESSION['correo_usuario']);
            return null;
        }
        $_SESSION['usuario'] = $usuario;
        $_SESSION['id_usuario'] = (int)$usuario['id_usuario'];
        $_SESSION['nombre_usuario'] = (string)($usuario['nombre_completo'] ?? '');
        $_SESSION['correo_usuario'] = (string)($usuario['correo'] ?? '');
        return $usuario;
    } catch (Throwable $e) {
        return null;
    }
}
function iniciarSesionUsuario(array $usuario): void {
    session_regenerate_id(true);
    $_SESSION['usuario'] = $usuario;
    $_SESSION['id_usuario'] = (int)$usuario['id_usuario'];
    $_SESSION['nombre_usuario'] = (string)($usuario['nombre_completo'] ?? '');
    $_SESSION['correo_usuario'] = (string)($usuario['correo'] ?? '');
}
function cerrarSesionUsuario(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}
function requiereLogin(): void {
    if (!usuarioActual()) {
        header('Location: login.php?modo=login&mensaje=necesitas');
        exit;
    }
}
function esFavorito(int $idLugar): bool {
    global $pdo;
    $usuario = usuarioActual();
    if (!$usuario) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM favoritos WHERE id_usuario = ? AND id_lugares = ? LIMIT 1');
    $stmt->execute([(int)$usuario['id_usuario'], $idLugar]);
    return (bool)$stmt->fetchColumn();
}
function fallbackImagen(string $tipo = ''): string {
    $fallbacks = [
        'Museo' => 'https://images.unsplash.com/photo-1564399579883-451a5d44ec08?auto=format&fit=crop&w=1400&q=88',
        'Restaurante' => 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=1400&q=88',
        'Mirador' => 'https://images.unsplash.com/photo-1500534623283-312aade485b7?auto=format&fit=crop&w=1400&q=88',
        'Bar' => 'https://images.unsplash.com/photo-1515003197210-e0cd71810b5f?auto=format&fit=crop&w=1400&q=88',
        'Cafetería' => 'https://images.unsplash.com/photo-1445116572660-236099ec97a0?auto=format&fit=crop&w=1400&q=88',
        'Teatro' => 'https://images.unsplash.com/photo-1503095396549-807759245b35?auto=format&fit=crop&w=1400&q=88',
        'Parque' => 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?auto=format&fit=crop&w=1400&q=88',
        'Centro comercial' => 'https://images.unsplash.com/photo-1555529669-e69e7aa0ba9a?auto=format&fit=crop&w=1400&q=88',
        'Playa' => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1400&q=88',
        'Plaza' => 'https://images.unsplash.com/photo-1494522358652-f30e61a60313?auto=format&fit=crop&w=1400&q=88',
        'Sitio histórico' => 'https://images.unsplash.com/photo-1524492412937-b28074a5d7da?auto=format&fit=crop&w=1400&q=88',
        'Centro cultural' => 'https://images.unsplash.com/photo-1518998053901-5348d3961a04?auto=format&fit=crop&w=1400&q=88',
        'Zoológico' => 'https://images.unsplash.com/photo-1549366021-9f761d450615?auto=format&fit=crop&w=1400&q=88',
        'Estadio' => 'https://images.unsplash.com/photo-1508098682722-e99c43a406b2?auto=format&fit=crop&w=1400&q=88',
        'Isla' => 'https://images.unsplash.com/photo-1505881502353-a1986add3762?auto=format&fit=crop&w=1400&q=88',
        'Jardín botánico' => 'https://images.unsplash.com/photo-1470770841072-f978cf4d019e?auto=format&fit=crop&w=1400&q=88'
    ];
    return $fallbacks[$tipo] ?? $fallbacks['Parque'];
}
function imagenLugar(string $nombre, string $ciudad = '', string $tipo = ''): string {
    $imagenes = [
        'Museo del Oro' => 'https://wp-content.miviaje.com/2019/07/museo-oro-bogota-768x512.jpg',
        'Museo Botero' => 'https://cdn.getyourguide.com/image/format=auto%2Cfit=crop%2Cgravity=auto%2Cquality=60%2Cwidth=400%2Cheight=265%2Cdpr=2/tour_img/488a12bc0c329a548c837c0fad2526a61eefed73c1d5c1dc439d33e63da1528a.jpg',
        'Monserrate' => 'https://radionacional-v3.s3.amazonaws.com/s3fs-public/node/article/field_image/MONSERRATE.jpg',
        'Andrés D.C.' => 'https://www.andrescarnederes.com/uploads/images/galeria/5e66d28e71295439474168.jpg',
        'Bogotá Beer Company Zona T' => 'https://cdn-italiani-media.italiani.it/site-bogota/sites/107/2021/10/Bogota-Beer-Company.jpg',
        'Wok Usaquén' => 'https://torresycarrizosa.com/wp-content/uploads/freshizer/4e051125f2a71c613b09bc2a54fafb6b_wok_01-0-550-c.jpg',
        'Juan Valdez Café Centro Andino' => 'https://images.squarespace-cdn.com/content/v1/6564dca6c8c33123bbdb700a/ffdf2015-3236-4c85-a6ef-1cac450b0e1a/rir-arquitectos-juan-valdez-andino-7.jpg?format=1500w',
        'Teatro Colón' => 'https://d219336yigegi3.cloudfront.net/sites/noticias-m2/files/field/image/9706_opt.jpg',
        'Museo de Antioquia' => 'https://museodeantioquia.co/wp-content/uploads/2024/05/20240119_183851653_iOS-1024x576.jpg',
        'Jardín Botánico de Medellín' => 'https://images.mnstatic.com/2a/e0/2ae02e7ab1e73c17508fb3a2a8e193da.jpg',
        'Parque Explora' => 'https://upload.wikimedia.org/wikipedia/commons/f/fc/Parque_Explora_%28Medell%C3%ADn%2C_Colombia%29.JPG',
        'El Cielo Restaurante' => 'https://images.trvl-media.com/lodging/64000000/63360000/63351300/63351242/03d3039a.jpg?impolicy=resizecrop&rw=900&rh=650&ra=fill',
        'Carmen Restaurante' => 'https://dynamic-media-cdn.tripadvisor.com/media/photo-o/32/53/5d/5c/bar-carmen.jpg?w=900&h=-1&s=1',
        'Envy Rooftop' => 'https://cdn.getyourguide.com/image/format=auto%2Cfit=crop%2Cgravity=auto%2Cquality=60%2Cwidth=900%2Cheight=600%2Cdpr=2/tour_img/d7dd78fcde006ed81f7c2af1115ef1b7769753e216892650fc57c6c7b0b9b2da.jpg',
        'Pergamino Café' => 'https://flokzu.com/wp-content/uploads/2023/12/pergamino-cafe-2.jpg',
        'Teatro Metropolitano' => 'https://api2.teatrometropolitano.com/storage/134/conversions/4KrR64l3lCroYd36evyp-thumb.png',
        'Loma de la Cruz' => 'https://www.qhubocali.com/wp-content/uploads/2023/02/1-1024x768-1.jpg',
        'Carbón de Palo Granada' => 'https://dynamic-media-cdn.tripadvisor.com/media/photo-o/33/a8/0b/52/caption.jpg?w=900&h=600&s=1',
        'La Topa Tolondra' => 'https://media.traveler.es/photos/628273c37a9ebe0e32ebb8c1/master/w_1600%2Cc_limit/Topa%25202.jpeg',
        'Jardin Plaza Cali' => 'https://www.elpais.com.co/resizer/v2/4YQKFE3COFBZPOLOSXXY6734VY.JPG?auth=6528e296722a569be385ee8ed2d9184cdb892e51505109f0e8968498c5e77627&smart=true&quality=75&width=1280&height=720',
        'Museo del Oro Zenú' => 'https://d3nmwx7scpuzgc2.cloudfront.net/sites/default/files/media/image/narravitas-transmedia-MOZ-zenu-cartagena-museo-2023-640-x400.jpg',
        'Playa de Bocagrande' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcThEcgsNUipQo4drF_Nqc66lumUKQ2bXI6VxD4ExdoGKE2D5Vi7XHNp6J3H&s=10',
        'Café Havana' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQv_7gfGpU7N1CLtoLzs80NUsQHCsxZeUUG4edc4YxCSb_6OqyD5_cuCN8&s=10',
        'Club de Pesca' => 'https://www.wradio.com.co/resizer/v2/W2D2HUYEUBFKHI5BSWPU7OJP64.jpg?auth=8f2e8c9834cc265bb8f295cf663586b7d70664be35f592ce926122418ecef592&quality=70&width=1000&height=600&smart=true',
        'Museo Romántico' => 'https://imagenes2.eltiempo.com/files/image_600_455/uploads/2022/04/06/624e24a3d460c.jpeg',
        'Plaza Botero' => 'https://upload.wikimedia.org/wikipedia/commons/e/ed/Medell%C3%ADn%2C_Plaza_Botero%2C_2023-07_CN-01.jpg',
        'Pueblito Paisa' => 'https://upload.wikimedia.org/wikipedia/commons/9/9c/Pueblito_Paisa_Medellin.jpg',
        'Cristo Rey' => 'https://upload.wikimedia.org/wikipedia/commons/9/9d/Cristo_Rey_Cali.jpg',
        'Castillo San Felipe de Barajas' => 'https://upload.wikimedia.org/wikipedia/commons/3/3c/Castillo_San_Felipe_de_Barajas.jpg',
        'Centro Comercial Santafé' => 'https://upload.wikimedia.org/wikipedia/commons/1/1c/Centro_Comercial_Santafe_Medellin.jpg',
        'Chorro de Quevedo' => 'https://upload.wikimedia.org/wikipedia/commons/9/98/Chorro_de_Quevedo_Bogota.jpg',
        'Museo La Tertulia' => 'https://upload.wikimedia.org/wikipedia/commons/7/7e/Museo_La_Tertulia_Cali.jpg',
        'Zoológico de Cali' => 'https://upload.wikimedia.org/wikipedia/commons/6/6b/Zoologico_de_Cali.jpg',
        'Islas del Rosario' => 'https://upload.wikimedia.org/wikipedia/commons/3/3d/Islas_del_Rosario_Colombia.jpg'
    ];
    return $imagenes[trim($nombre)] ?? fallbackImagen($tipo);
}
function precioLugar(string $nombre, string $tipo = ''): array {
    $mapa = [
        'Museo del Oro' => ['nivel' => 1, 'corto' => '$10.000', 'detalle' => 'Entrada nacional 2026. Domingos gratuita.', 'fuente' => 'Banco de la República'],
        'Museo Botero' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Entrada gratuita.', 'fuente' => 'Bogotá.gov.co'],
        'Monserrate' => ['nivel' => 3, 'corto' => 'Tarifa vigente', 'detalle' => 'El transporte por teleférico/funicular tiene tarifa propia; consulta el valor del día.', 'fuente' => 'Cerro de Monserrate'],
        'Jardín Botánico de Medellín' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Entrada al jardín sin costo.', 'fuente' => 'Referencia del proyecto'],
        'Parque Explora' => ['nivel' => 3, 'corto' => 'Combo aprox. $74.000', 'detalle' => 'Referencia 2026 para combo Explora + Planetario; residentes de Medellín pueden tener gratuidad con requisitos.', 'fuente' => 'Referencia 2026'],
        'Museo del Oro Zenú' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Entrada gratuita según la referencia del proyecto.', 'fuente' => 'Referencia del proyecto'],
        'Loma de la Cruz' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Espacio público.', 'fuente' => 'Referencia del proyecto'],
        'Plaza de Bolívar' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Espacio público.', 'fuente' => 'Referencia del proyecto'],
        'Parque de la 93' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'El acceso al parque es libre; el gasto depende del consumo alrededor.', 'fuente' => 'Referencia del proyecto'],
        'Plaza Botero' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Espacio público.', 'fuente' => 'Referencia del proyecto'],
        'Mirador Comuna 13' => ['nivel' => 2, 'corto' => 'Libre + tour opcional', 'detalle' => 'El sector es público; recorridos guiados pueden tener costo.', 'fuente' => 'Referencia del proyecto'],
        'Pueblito Paisa' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Acceso al sitio público.', 'fuente' => 'Referencia 2026'],
        'Cristo Rey' => ['nivel' => 2, 'corto' => 'Acceso libre', 'detalle' => 'El gasto depende de transporte y consumo.', 'fuente' => 'Referencia del proyecto'],
        'Castillo San Felipe de Barajas' => ['nivel' => 4, 'corto' => '$33.000 nacional', 'detalle' => '2026: nacionales $33.000; tarifa plena $38.000.', 'fuente' => 'Fortificaciones Cartagena de Indias'],
        'Playa de Bocagrande' => ['nivel' => 2, 'corto' => 'Playa libre', 'detalle' => 'Acceso a la playa; servicios y actividades son variables.', 'fuente' => 'Referencia del proyecto'],
        'Islas del Rosario' => ['nivel' => 4, 'corto' => 'Pasadía / operador', 'detalle' => 'Transporte y pasadía varían según operador y plan.', 'fuente' => 'Precio variable'],
        'Teatro Colón' => ['nivel' => 3, 'corto' => 'Según función', 'detalle' => 'La boleta depende del evento y ubicación.', 'fuente' => 'Precio variable'],
        'Teatro Metropolitano' => ['nivel' => 3, 'corto' => 'Según evento', 'detalle' => 'La boleta depende de la función.', 'fuente' => 'Precio variable'],
        'Zoológico de Cali' => ['nivel' => 3, 'corto' => 'Según tarifa vigente', 'detalle' => 'La entrada cambia por temporada y categoría.', 'fuente' => 'Precio variable']
    ];
    if (isset($mapa[$nombre])) {
        return $mapa[$nombre];
    }
    $variables = [
        'Restaurante' => ['nivel' => 3, 'corto' => 'Consumo $$–$$$$', 'detalle' => 'El total depende del consumo.', 'fuente' => 'Precio variable'],
        'Cafetería' => ['nivel' => 2, 'corto' => 'Consumo $–$$', 'detalle' => 'El total depende del consumo.', 'fuente' => 'Precio variable'],
        'Bar' => ['nivel' => 3, 'corto' => 'Consumo $$–$$$', 'detalle' => 'El total depende del consumo y del establecimiento.', 'fuente' => 'Precio variable'],
        'Centro comercial' => ['nivel' => 2, 'corto' => 'Acceso libre', 'detalle' => 'Entrar no tiene costo; compras y consumo son variables.', 'fuente' => 'Precio variable'],
        'Centro cultural' => ['nivel' => 2, 'corto' => 'Según programación', 'detalle' => 'Puede haber actividades gratuitas o con boleta.', 'fuente' => 'Precio variable'],
        'Sitio histórico' => ['nivel' => 2, 'corto' => 'Según sitio', 'detalle' => 'Algunos espacios son libres y otros cobran entrada.', 'fuente' => 'Precio variable'],
        'Plaza' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Espacio público; gasto variable por consumo cercano.', 'fuente' => 'Referencia del proyecto'],
        'Parque' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Espacio público.', 'fuente' => 'Referencia del proyecto'],
        'Jardín botánico' => ['nivel' => 1, 'corto' => 'Gratis', 'detalle' => 'Acceso sin costo según referencia del proyecto.', 'fuente' => 'Referencia del proyecto'],
        'Estadio' => ['nivel' => 3, 'corto' => 'Según evento', 'detalle' => 'La boleta depende del partido o espectáculo.', 'fuente' => 'Precio variable'],
        'Isla' => ['nivel' => 4, 'corto' => 'Según operador', 'detalle' => 'Transporte y actividades son variables.', 'fuente' => 'Precio variable']
    ];
    return $variables[$tipo] ?? ['nivel' => 2, 'corto' => 'Precio variable', 'detalle' => 'Confirma el valor antes de salir.', 'fuente' => 'Precio variable'];
}
function presupuestoTexto(int $nivel): string {
    return match ($nivel) {
        1 => '$',
        2 => '$$',
        3 => '$$$',
        default => '$$$$'
    };
}
function fechaBonita(?string $fecha): string {
    if (!$fecha) {
        return 'Sin fecha registrada';
    }
    $partes = explode('-', $fecha);
    if (count($partes) !== 3) {
        return $fecha;
    }
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $mes = (int)$partes[1];
    return (int)$partes[2] . ' de ' . ($meses[$mes - 1] ?? '') . ' de ' . $partes[0];
}