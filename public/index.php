<!doctype html>
<html>
    <head>
        <title></title>
    </head>
    <body>
        <form method="post">
            <label>Search the IndieWeb</label>
            <input type="search" name="q" value="<?=isset($_POST['q'])?$_POST['q']:''?>">
            <input type="submit">
        </form>
        <ol>
        <?php
            if (array_key_exists('q', $_POST)) {
                $sitefetch = curl_multi_init();
                $sitehandlers = [];
                foreach ([
                    'https://rosemaryorchard.com/',
                    'https://doubleloop.net/',
                    'https://diggingthedigital.com/',
                    'https://www.zylstra.org/blog/',
                    'https://www.fornacalia.com/',
                    'https://snarfed.org/',
                    'https://boffosocko.com/',
                    'https://david.shanske.com/',
                    'https://notiz.blog/',
                ] as $site) {
                    // ?rest_route=/wp/v2/search&per_page=5&search=
                    $query = http_build_query([
                        'rest_route' => '/wp/v2/search',
                        'per_page' => '5',
                        'search' => $_POST['q'],
                    ]);
                    $sitehandler = curl_init($site . '?' . $query);
                    curl_setopt($sitehandler, CURLOPT_RETURNTRANSFER, true);
                    curl_multi_add_handle($sitefetch, $sitehandler);
                    $sitehandlers[] = $sitehandler;
                }
                $running = null;
                do {
                    curl_multi_exec($sitefetch, $running);
                } while ($running);
                foreach ($sitehandlers as $sitehandler) {
                    curl_multi_remove_handle($sitefetch, $sitehandler);
                }
                curl_multi_close($sitefetch);
                $resultfetch = curl_multi_init();
                $resulthandlers = [];
                foreach ($sitehandlers as $sitehandler) {
                    foreach (json_decode(curl_multi_getcontent($sitehandler)) as $result) {
                        $resulthandler = curl_init($result->_links->self[0]->href);
                        curl_setopt($resulthandler, CURLOPT_RETURNTRANSFER, true);
                        curl_multi_add_handle($resultfetch, $resulthandler);
                        $resulthandlers[] = $resulthandler;
                    }
                }
                $running = null;
                do {
                    curl_multi_exec($resultfetch, $running);
                } while ($running);
                foreach ($resulthandlers as $resulthandler) {
                    curl_multi_remove_handle($resultfetch, $resulthandler);
                }
                curl_multi_close($resultfetch);
                $pdo = new PDO('sqlite::memory:');
                $pdo->query('CREATE VIRTUAL TABLE indexer USING FTS5(title,content,url)');
                foreach ($resulthandlers as $resulthandler) {
                    $result = json_decode(curl_multi_getcontent($resulthandler));
                    $pdo->prepare('INSERT INTO indexer(title,content,url) VALUES (?,?,?)')->execute([
                        $result->title->rendered,
                        $result->content->rendered,
                        $result->link,
                    ]);
                }

                $stmt = $pdo->prepare('SELECT * FROM indexer WHERE indexer MATCH ? ORDER BY rank');
                $stmt->execute([$_POST['q']]);
                foreach ($stmt->fetchAll() as $result) {
                    echo '<li>';
                    if (strlen($result['title']) > 0) {
                        echo '<h2><a href="' . $result['url'] . '">' . $result['title'] . '</a></h2>';
                    } else {
                        echo '<h2><a href="' . $result['url'] . '">[No Title]</a></h2>';
                    }
                    echo '<p style="color:darkgreen;text-decoration:underline">' . parse_url($result['url'], PHP_URL_HOST) . '</p>';
                    $content = explode(' ', strip_tags($result['content']), 20);
                    if (count($content) >= 20) {
                        array_pop($content);
                        $content = implode(' ', $content) . '…';
                    } else {
                        $content = implode(' ', $content);
                    }
                    echo '<p>' . $content . '</p>';
                    echo '</li>';
                }
            }
        ?>
        </ol>
    </body>
</html>

