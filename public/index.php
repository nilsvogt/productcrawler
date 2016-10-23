<?php
    ini_set('max_execution_time', 300);

    $languages = ['it'];

    if(!empty($_FILES) && $_FILES['sourcefile'] && $_FILES['sourcefile']['error'] === UPLOAD_ERR_OK) {

        $file = $_FILES['sourcefile'];
        $lang = $_POST['lang'];

        if(!in_array($lang, $languages)) {
            exit(1);
        }

        move_uploaded_file($file['tmp_name'], './../uploads/sourcefile.csv');

        exec('./../App/app.php products:crawl uploads/sourcefile.csv uploads/result.csv ' . $lang);

        header("Content-Type: text/csv");

        header("Content-Disposition: attachment; filename=products-" . $lang . ".csv");

        readfile('./../uploads/result.csv');
    }
?>
<!doctype html>
<html>
    <head>

    </head>
    <body>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="sourcefile" />
            <select name="lang">
                <option value="it" selected>it</option>
            </select>
            <button type="submit">submit</button>
        </form>
    </body>
</html>
