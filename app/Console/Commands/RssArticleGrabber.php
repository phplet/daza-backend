<?php

namespace App\Console\Commands;

use App\Models\Topic;
use App\Models\Article;
use App\Models\ArticleTag;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ClientException;
use Carbon\Carbon;
use PHPHtmlParser\Dom;

use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;

class RssArticleGrabber extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ag:rss';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nothing';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $lists = Topic::orderBy('id', 'desc')->where('source_format', 'rss+xml')->get();

        $client = new Client();

        $requests = function ($lists) {
            foreach ($lists as $key => $topic) {
                $source_link = $topic->source_link;
                $this->info($source_link);
                yield new Request('GET', $source_link);
            }
        };

        $pool = new Pool($client, $requests($lists), [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) use ($lists) {
                // this is delivered each successful response
                $body = mb_convert_encoding($response->getBody()->getContents(), "UTF-8");

                $xml = simplexml_load_string( $body );

                $topic = $lists[$index];

                $data = [
                    'user_id'  => $topic->user_id,
                    'topic_id' => $topic->id,
                ];

                foreach ($xml->channel->item as $key => $value) {
                    $image_url    = '';
                    $author       = '';
                    $article_tags = [];

                    $dom = new Dom();
                    $dom->load($value->description);

                    $author = $value->author;
                    if (strlen($author) === 0) {
                        $author = $value->children('dc', true)->creator;
                    }

                    $img_tags = $dom->find('img');
                    foreach ($img_tags as $img) {
                        $image_url = $img->getAttribute('src');
                        // 如果图片链接为空，则跳过下方的处理
                        if (strlen($image_url) === 0) {
                            continue;
                        }
                        // 省略嵌入式资源处理
                        if (0 === strpos($image_url, '//')) {
                            $image_url = 'http:' . $image_url;
                        }
                        // 判断是否为 .jpg、.jpeg、.png、.bmp 类型的图片，如果不是则跳过
                        if (strpos($image_url, '.jpg')  > 0 ||
                            strpos($image_url, '.jpeg') > 0 ||
                            strpos($image_url, '.png')  > 0 ||
                            strpos($image_url, '.bmp')  > 0) {
                            break;
                        } else {
                            $image_url = '';
                        }
                    }
                    // 获取文章的标签
                    $category_tags = $value->category;
                    foreach ($category_tags as $category) {
                        array_push($article_tags, new ArticleTag(['name' => ((string) $category)]));
                    }

                    $article = Article::firstOrCreate(array_merge($data, ['guid' => $value->guid]));

                    $article->update([
                        'type'          => 'feed',
                        'link'          => $value->link,
                        'title'         => $value->title,
                        'content'       => $value->description,
                        'image_url'     => $image_url,
                        'author'        => $author,
                        'published_at'  => new DateTime($value->pubDate),
                    ]);
                    $article->tags()->forceDelete();
                    $article->tags()->saveMany($article_tags);
                }
            },
            'rejected' => function ($reason, $index) {
                // this is delivered each failed request
                $this->error("rejected reason: " . $reason );
            },
        ]);

        // Initiate the transfers and create a promise
        $promise = $pool->promise();

        // Force the pool of requests to complete.
        $promise->wait();

        $this->comment(PHP_EOL.Inspiring::quote().PHP_EOL);
    }
}
