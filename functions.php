<?php

function ReadJson($file)
{
    if(!file_exists($file))
    {
        return [];
    }
    $json = file_get_contents($file);
    return json_decode($json, true);
}

function WriteJson($file, $data)
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function EnsureDir($dir)
{
    if(!is_dir($dir))
    {
        mkdir($dir, 0777, true);
    }
}

function CreateUniqueFileName($dir, $name)
{
    $filename = pathinfo($name, PATHINFO_FILENAME);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $NewName = $name;
    $i = 0;
    while(file_exists($dir . $NewName))
    {
        $i++;
        $NewName = $filename . '_' . $i . '.' . $ext;
    }
    return $NewName;
}

function Upload_Image($file, $description ='')
{
    global $config;
    //чекать php.ini
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) 
    {
        return ['error' => 'Ошибка загрузки (код: ' . ($file['error'] ?? 'N/A') . ')'];
    }

    if (isset($config['max_file_size']) && (int)$file['size'] > $config['max_file_size']) 
    {
        return ['error' => 'Файл превышает максимальный размер'];
    }

    $verified_image = @getimagesize($file['tmp_name']);
    if ($verified_image === false) 
        {
            return ['error' => 'Загружаемый файл не является изображением!'];
        }
    $mime = $verified_image['mime'] ?? null;
    if (!$mime && function_exists('finfo_open'))
        {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }

    if (!$mime)
        {
            return ['error' => 'Не удалось определить MIME-тип файла.'];
        }


    if (empty($config['allowed_types']) || !in_array($mime, $config['allowed_types'], true))
        {
            return ['error' => 'Недопустимый формат изображения: ' . htmlspecialchars($mime)];
        }

    EnsureDir($config['images_dir']);
    EnsureDir($config['thumbnails_dir']);

    $unique_name = CreateUniqueFileName($config['images_dir'], $file['name']);
    $images_dir = rtrim($config['images_dir'], '/\\') . DIRECTORY_SEPARATOR;
    $thumbs_dir = rtrim($config['thumbnails_dir'], '/\\') . DIRECTORY_SEPARATOR;
    $destination = $images_dir . $unique_name;
    $thumb_destination = $thumbs_dir . $unique_name;

    if (!is_uploaded_file($file['tmp_name']) || !move_uploaded_file($file['tmp_name'], $destination)) 
        {
            return ['error' => 'Не удалось сохранить файл на сервер'];
        }
    
    $thumb_succ = false;
    if (function_exists('CreateThumbnail')) 
    {
        $thumb_succ = CreateThumbnail($destination, $thumb_destination, $config['thumbnail_size']);
        if ($thumb_succ !== true)
            {
                error_log("Функция CreateThumbnail вернула false для $destination");
            }
    }
    if ($thumb_succ !== true)
    {
        if(!copy($destination, $thumb_destination)) 
            {
                @unlink($destination);
                return ['error' => 'Не удалось создать превью/скопировать оригинал'];
            }   
    }

    if(!AddWatermark($thumb_destination)) 
    {
        error_log("Функция AddWatermark не сработала для $thumb_destination");
    }

    $record = [
        'name'        => $unique_name,
        'path'        => 'upload/img_full/' . $unique_name,
        'thumbnail'   => 'upload/img_thumbnails/' . $unique_name,
        'size'        => (int)$file['size'],
        'uploaded_at' => date('Y-m-d H:i:s'),
        'description' => mb_convert_encoding(trim($description), 'UTF-8', 'auto')
    ];

    $add = CreateJsonRecord($record);
    if (isset($add['error'])) {
        return ['error' => 'Файл сохранён, но запись в JSON не выполнена: ' . $add['error']];
    }

    return ['success' => true, 'name' => $unique_name];
}

function CreateJsonRecord(array $record)
{
    global $config;
    $jsonFile = $config['json_path'];
    $dir = dirname($jsonFile);
    if (!is_dir($dir)) 
    {
        mkdir($dir, 0777, true);
    }

    $data = [];
    if (file_exists($jsonFile)) {
        $contents = file_get_contents($jsonFile);
        $data = json_decode($contents, true) ?: [];
    }

    array_unshift($data, $record);

    $tmp = $jsonFile . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) 
        {
        return ['error' => 'Не удалось записать JSON'];
        }
    rename($tmp, $jsonFile);
    return ['ok' => true];
}
/////////////////////////////////////////////////////////////////////////////////todo
function AddWatermark($destination)
{
    global $config;

    if (!file_exists($destination) || !is_readable($destination)) 
        {
        error_log("Файл не существует/не может быть прочтён: $destination");
        return false;
        }
    $info = getimagesize($destination);
    if (!$info) return false;

    switch ($info['mime']) {
        case 'image/jpeg':
            $img = imagecreatefromjpeg($destination);
            break;
        case 'image/png':
            $img = imagecreatefrompng($destination);
            break;
        case 'image/gif':
            $img = imagecreatefromgif($destination);
            break;
        default:
            error_log("Не удалось создать изображение из $destination");
            return false;
    }

    $watermark = @imagecreatefrompng($config['watermark_path']);

    $img_w = imagesx($img);
    $img_h = imagesy($img);
    $wm_w = imagesx($watermark);
    $wm_h = imagesy($watermark);

    $scale = 0.2;
    $new_w = intval($img_w * $scale);
    $new_h = intval($wm_h * $new_w / $wm_w);

    $resized_wm = imagecreatetruecolor($new_w, $new_h);
    imagesavealpha($resized_wm, true);
    $trans = imagecolorallocatealpha($resized_wm, 0, 0, 0, 127);
    imagefill($resized_wm, 0, 0, $trans);

    imagecopyresampled($resized_wm, $watermark, 0, 0, 0, 0, $new_w, $new_h, $wm_w, $wm_h);

    $dst_x = $img_w - $new_w - 10;
    $dst_y = $img_h - $new_h - 10;
    imagecopy($img, $resized_wm, $dst_x, $dst_y, 0, 0, $new_w, $new_h);

    $text_color = imagecolorallocate($img, 255, 255, 255);
    $font_size = 5;
    $date = date('Y-m-d H:i');
    imagestring($img, $font_size, 10, 10, $date, $text_color);

    switch ($info['mime']) {
        case 'image/jpeg':
            imagejpeg($img, $destination, 90);
            break;
        case 'image/png':
            imagepng($img, $destination);
            break;
        case 'image/gif':
            imagegif($img, $destination);
            break;
    }

    imagedestroy($img);
    imagedestroy($watermark);
    imagedestroy($resized_wm);

    return true;
}

function CreateThumbnail($source, $dest, $thumb_size = 300)
{
    $info = getimagesize($source);
    if (!$info) return false;

    switch ($info['mime']) {
        case 'image/jpeg':
            $img = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $img = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $img = imagecreatefromgif($source);
            break;
        default:
            return false;
    }

    if (!$img) return false;

    list($width, $height) = [$info[0], $info[1]];

    if ($width > $height) {
        $new_width = $thumb_size;
        $new_height = intval($height * $thumb_size / $width);
    } else {
        $new_height = $thumb_size;
        $new_width = intval($width * $thumb_size / $height);
    }

    $thumb = imagecreatetruecolor($new_width, $new_height);

    if ($info['mime'] === 'image/png' || $info['mime'] === 'image/gif') {
        imagesavealpha($thumb, true);
        $trans = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefill($thumb, 0, 0, $trans);
    }

    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    switch ($info['mime']) {
        case 'image/jpeg':
            imagejpeg($thumb, $dest, 90);
            break;
        case 'image/png':
            imagepng($thumb, $dest);
            break;
        case 'image/gif':
            imagegif($thumb, $dest);
            break;
    }

    imagedestroy($img);
    imagedestroy($thumb);

    return true;
}