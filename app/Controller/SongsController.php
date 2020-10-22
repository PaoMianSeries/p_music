<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */
namespace App\Controller;

use Hyperf\Utils\Exception\ParallelExecutionException;
use Hyperf\Utils\Parallel;
use phpseclib\Crypt\RSA;

class SongsController extends AbstractController
{
    /**
     * 获取音乐 url.
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getUrl()
    {
        $validator = $this->validationFactory->make($this->request->all(), [
            'id' => 'required',
            'br' => '',
        ]);
        if ($validator->fails()) {
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->returnMsg(422, $errorMessage);
        }
        $validated_data = $validator->validated();

        $cookie = $this->request->getCookieParams();
        if (! isset($cookie['MUSIC_U'])) {
            $cookie['_ntes_nuid'] = bin2hex($this->commonUtils->randString(16));
        }
        $cookie['os'] = 'pc';

        $data['ids'] = '[' . $validated_data['id'] . ']';
        $data['br'] = (int) ($validated_data['br'] ?? 999000);
        $result = $this->createCloudRequest(
            'POST',
            'https://interface3.music.163.com/eapi/song/enhance/player/url',
            $data,
            ['crypto' => 'eapi', 'cookie' => $cookie, 'url' => '/api/song/enhance/player/url']
        );
        $data = json_decode($result->getBody()->getContents(), true);
        foreach ($data['data'] as $k => $datum) {
            if (empty($datum['url'])) {
                if ($this->cache->has($datum['id'] . '_song_url')) {
                    $song_cache = $this->cache->get($datum['id'] . '_song_url');
                    $other_song_url = $song_cache['song']['url'] ?? '';
                } else {
                    $other_song_url = $this->getUrlFormOther((string) $datum['id']);
                }
                if (! empty($other_song_url)) {
                    $data['data'][$k]['url'] = $other_song_url;
                    $data['data'][$k]['br'] = 128000;
                    $data['data'][$k]['code'] = 200;
                    $data['data'][$k]['type'] = 'mp3';
                    $data['data'][$k]['encodeType'] = 'mp3';
                }
            }
        }
        return $this->response->json($data);
    }

    /**
     * 音乐是否可用.
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function checkMusic()
    {
        $validator = $this->validationFactory->make($this->request->all(), [
            'id' => 'required',
            'br' => '',
        ]);
        if ($validator->fails()) {
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->returnMsg(422, $errorMessage);
        }
        $validated_data = $validator->validated();

        $data['ids'] = '[' . (int) $validated_data['id'] . ']';
        $data['br'] = (int) ($validated_data['br'] ?? 999000);
        $res = $this->createCloudRequest(
            'POST',
            'https://music.163.com/weapi/song/enhance/player/url',
            $data,
            ['crypto' => 'weapi', 'cookie' => $this->request->getCookieParams()]
        );
        try {
            $playable = false;
            $body = $res->getBody()->getContents();
            $body = json_decode($body, true);
            if ($res->getStatusCode() == 200) {
                //freeTrialInfo试听信息，为null是非试听
                if ($body['data'][0]['code'] == 200 && empty($body['data'][0]['freeTrialInfo'])) {
                    $playable = true;
                }
            }
            if ($playable) {
                return $this->response->json([
                    'success' => true,
                    'message' => 'ok',
                ])->withStatus(200);
            }
            //搜索其它源
            $other_song_url = $this->getUrlFormOther((string) $body['data'][0]['id']);
            if (! empty($other_song_url)) {
                return $this->response->json([
                    'success' => true,
                    'message' => 'ok',
                ])->withStatus(200);
            }

            return $this->response->json([
                'success' => false,
                'message' => '亲爱的,暂无版权',
            ])->withStatus(404);
        } catch (\Exception $e) {
            return $this->response->json([
                'code' => 500,
                'msg' => $e,
            ])->withStatus(500);
        }
    }

    /**
     * 获取歌曲详情.
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getDetail()
    {
        $validator = $this->validationFactory->make($this->request->all(), [
            'ids' => 'required',
        ]);
        if ($validator->fails()) {
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->returnMsg(422, $errorMessage);
        }
        $validated_data = $validator->validated();

        return $this->getSongDetail($validated_data['ids']);
    }

    /**
     * 获取歌曲详情.
     * @param string $song_ids 歌曲ID
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function getSongDetail($song_ids)
    {
        $ids = explode(',', $song_ids);
        $temp_lists = [];
        foreach ($ids as $id) {
            $temp_lists[] = '{"id":' . $id . '}';
        }
        $data = [
            'c' => '[' . implode(',', $temp_lists) . ']',
            'ids' => '[' . $song_ids . ']',
        ];
        return $this->createCloudRequest(
            'POST',
            'https://music.163.com/weapi/v3/song/detail',
            $data,
            ['crypto' => 'weapi', 'cookie' => $this->request->getCookieParams()]
        );
    }

    /**
     * 调整歌曲顺序.
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function updateOrder()
    {
        $validator = $this->validationFactory->make($this->request->all(), [
            'pid' => 'required',
            'ids' => 'required',
        ]);
        if ($validator->fails()) {
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->returnMsg(422, $errorMessage);
        }
        $validated_data = $validator->validated();

        $data['pid'] = $validated_data['pid'];
        $data['trackIds'] = $validated_data['ids'];
        $data['op'] = 'update';

        return $this->createCloudRequest(
            'POST',
            'http://interface.music.163.com/api/playlist/manipulate/tracks',
            $data,
            ['crypto' => 'weapi', 'cookie' => $this->request->getCookieParams(), 'url' => '/api/playlist/desc/update']
        );
    }

    /**
     * 从其它来源获取地址
     * @param string $id 歌曲ID
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return string
     */
    public function getUrlFormOther($id)
    {
        //读缓存
        if ($this->cache->has($id . '_song_url')) {
            $res = $this->cache->get($id . '_song_url');
            return $res['song']['url'] ?? '';
        }
        $song_info_res = $this->getSongDetail($id);
        $song_info = json_decode($song_info_res->getBody()->getContents(), true);

        $song_name = $song_info['songs'][0]['name'] ?? ''; //歌曲名
        $song_ar_name = $song_info['songs'][0]['ar'][0]['name'] ?? ''; //歌手
        $res = [];
        if (! empty($song_name)) {
            $parallel = new Parallel();
            $parallel->add(function () use ($song_name, $song_ar_name) {
                return $this->getBaidu($song_name, $song_ar_name);
            }, 'baidu');
            $parallel->add(function () use ($song_name, $song_ar_name) {
                return $this->getXiaMi($song_name, $song_ar_name);
            }, 'xiami');
            $parallel->add(function () use ($song_name, $song_ar_name) {
                return $this->getKuWo($song_name, $song_ar_name);
            }, 'kuwo');
            $parallel->add(function () use ($song_name, $song_ar_name) {
                return $this->getKuGou($song_name, $song_ar_name);
            }, 'kugou');
            $parallel->add(function () use ($song_name, $song_ar_name) {
                return $this->getMiGu($song_name, $song_ar_name);
            }, 'migu');
            $parallel->add(function () use ($song_name, $song_ar_name) {
                return $this->getQQ($song_name, $song_ar_name);
            }, 'qq');
            try {
                $results = $parallel->wait();
            } catch (ParallelExecutionException $e) {
                $results = $e->getResults();
//                dump($e->getThrowables()); //获取协程中出现的异常。
            }
            $sorts = ['migu', 'qq', 'xiami', 'kuwo', 'baidu', 'kugou'];
            $res['song']['url'] = '';
            foreach ($sorts as $sort) {
                if (isset($results[$sort])) {
                    if (is_array($results[$sort])) {
                        if (! empty($results[$sort][0]) && empty($res['song']['url'])) {
                            $res['song']['url'] = $results[$sort][0];
                            $res['song']['from'] = $sort;
                        }
                        $res['from'][$sort] = $results[$sort];
                    }
                }
            }
            if (!empty($res['song']['url'])) {
                //写缓存
                $this->cache->set($id . '_song_url', $res, 60 * 30);
            } else {
                $this->cache->set($id . '_song_url', $res, 5);
            }
        }
        return $res['song']['url'] ?? '';
    }

    /**
     * baidu源.
     * @param $name
     * @param $ar_name
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return array
     */
    public function getBaidu($name, $ar_name)
    {
        $song_url = '';
        $song_size = 0;
        if (! empty($name)) {
            $key = urlencode(trim($name . ' ' . $ar_name));
            //search
            $search_url = 'http://musicapi.taihe.com/v1/restserver/ting?' .
                'from=qianqianmini&method=baidu.ting.search.merge&' .
                'isNew=1&platform=darwin&page_no=1&page_size=30&' .
                'query=' . $key . '&version=11.2.1';
            $headers = [
                'accept' => 'application/json, text/plain, */*',
                'accept-encoding' => 'gzip, deflate',
                'accept-language' => 'zh-CN,zh;q=0.9',
                'user-agent' => $this->chooseUserAgent('pc'),
                'X-Real-IP' => $this->chooseChinaIp(),
            ];
            $client = $this->clientFactory->create();
            $client_params['headers'] = $headers ?? [];
            $response = $client->request('GET', $search_url, $client_params);
            if ($response->getStatusCode() == 200) {
                $body = $response->getBody()->getContents();
                $body = json_decode($body, true);
                $song_id = 0;
                if (isset($body['result'], $body['result']['song_info'])) {
                    if (isset($body['result']['song_info']['song_list']) && is_array($body['result']['song_info']['song_list'])) {
                        foreach ($body['result']['song_info']['song_list'] as $item) {
                            if (mb_strpos($item['title'], $name) !== false) {
                                $song_id = $item['song_id'];
                                break;
                            }
                        }
                    }
                }
                if ($song_id > 0) {
                    //get url
                    $get_url = 'http://music.taihe.com/data/music/fmlink?songIds=' . $song_id . '&type=mp3';
                    $response2 = $client->request('GET', $get_url, $client_params);
                    if ($response2->getStatusCode() == 200) {
                        $body = $response2->getBody()->getContents();
                        $body = json_decode($body, true);
                        $song_url = $body['data']['songList'][0]['songLink'] ?? '';
                        $song_size = $body['data']['songList'][0]['size'] ?? 0;
                    }
                }
            }
        }
        return [$song_url, $song_size];
    }

    /**
     * xiami源.
     * @param $name
     * @param $ar_name
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return array
     */
    public function getXiaMi($name, $ar_name)
    {
        $song_url = '';
        $song_size = 0;
        if (! empty($name)) {
            $key = trim($name . ' ' . $ar_name);

            $client_opt = [
                'verify' => false,
            ];
            $headers = [
                'user-agent' => $this->chooseUserAgent('pc'),
                'X-Real-IP' => $this->chooseChinaIp(),
            ];
            //way_1
//            $jar = new \GuzzleHttp\Cookie\CookieJar();
//            $client_opt = [
//                'verify' => false,
//                'cookies' => $jar
//            ];
//            $client = $this->clientFactory->create($client_opt);
//            //token
//            $url = 'https://www.xiami.com';
//            $client->request('GET', $url, [
//                'headers' => $headers
//            ]);
//            //search
//            $xm_sg_tk = $jar->getCookieByName('xm_sg_tk')->getValue();
//            if (empty($xm_sg_tk)) {
//                return [$song_url, $song_size];
//            }
//            $query = json_encode(['key' => $key, 'pagingVO' => ['page' => 1, 'pageSize' => 60]]);
//            $message = head(explode('_', $xm_sg_tk)) . '_xmMain_/api/search/searchSongs_' . $query;
//            $search_url = 'https://www.xiami.com/api/search/searchSongs?_q=' . urlencode($query) .
//                '&_s=' . md5($message);
//            $headers = array_merge($headers, [
//                'referer' => 'https://www.xiami.com/search?key=' . urlencode($key)
//            ]);
//            $client_params['headers'] = $headers ?? [];
//            $response = $client->request('GET', $search_url, $client_params);
            //way_2
            //search
            $client = $this->clientFactory->create($client_opt);
            $search_url = 'http://api.xiami.com/web?v=2.0&app_key=1' .
                '&key=' . urlencode($key) . '&page=1&limit=20&callback=jsonp&r=search/songs';
            $headers = array_merge($headers, [
                'accept' => 'application/json, text/plain, */*',
                'accept-encoding' => 'gzip, deflate',
                'accept-language' => 'zh-CN,zh;q=0.9',
                'referer' => 'https://h.xiami.com/',
            ]);
            $client_params['headers'] = $headers ?? [];
            $response = $client->request('GET', $search_url, $client_params);

            if ($response->getStatusCode() == 200) {
                $body = $response->getBody()->getContents();
                $song_id = 0;
                //way_1
//                $body = $response->getBody()->getContents();
//                $body = json_decode($body, true);
//                if (isset($body['code']) && $body['code'] == 'SUCCESS') {
//                    foreach ($body['result']['data']['songs'] as $song) {
//                        if ($song['songName'] == $name) {
//                            $song_id = $song['songId'];
//                            break;
//                        }
//                    }
//                }
                //way_2
                preg_match('/jsonp\((.*)\)/', $body, $matches);
                if (isset($matches[1])) {
                    $body_array = json_decode($matches[1], true);
                } else {
                    $body_array = [];
                }
                if (isset($body_array['data']['songs'])) {
                    foreach ($body_array['data']['songs'] as $song) {
                        if (mb_strpos($song['song_name'], $name) !== false) {
                            $song_id = $song['song_id'];
                            break;
                        }
                    }
                }
                if ($song_id > 0) {
                    //way_1
                    //way_2
                    $get_url = 'https://api.xiami.com/web?v=2.0&app_key=1&id=' . $song_id . '&callback=jsonp&r=song/detail';
                    $response2 = $client->request('GET', $get_url, $client_params);
                    if ($response2->getStatusCode() == 200) {
                        $body = $response2->getBody()->getContents();
                        preg_match('/jsonp\((.*)\)/', $body, $matches);
                        if (isset($matches[1])) {
                            $body_array = json_decode($matches[1], true);
                        } else {
                            $body_array = [];
                        }
                        $song_url = $body_array['data']['song']['listen_file'] ?? '';
                    }
                }
            }
        }

        return [$song_url, $song_size];
    }

    /**
     * kuwo源.
     * @param $name
     * @param $ar_name
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return array
     */
    public function getKuWo($name, $ar_name)
    {
        $song_url = '';
        $song_size = 0;
        if (! empty($name)) {
            $key = urlencode(trim(str_replace(' - ', '', ($name . ' ' . $ar_name))));

            $jar = new \GuzzleHttp\Cookie\CookieJar();
            $client_opt = [
                'verify' => false,
                'cookies' => $jar,
            ];
            $headers = [
                'user-agent' => $this->chooseUserAgent('pc'),
                'X-Real-IP' => $this->chooseChinaIp(),
            ];
            $client = $this->clientFactory->create($client_opt);
            //token
            $client->request('GET', 'http://kuwo.cn/search/list?key=' . $key);
            //search
            $kw_token = $jar->getCookieByName('kw_token')->getValue();
            $search_url = 'http://www.kuwo.cn/api/www/search/searchMusicBykeyWord?key=' . $key . '&pn=1&rn=30';
            $headers = array_merge($headers, [
                'referer' => 'http://www.kuwo.cn/search/list?key=' . $key,
                'csrf' => $kw_token,
            ]);
            $client_params['headers'] = $headers ?? [];
            $response = $client->request('GET', $search_url, $client_params);
            if ($response->getStatusCode() == 200) {
                $body = $response->getBody()->getContents();
                $body = json_decode($body, true);
                $song_id = '';
                if (isset($body['data']['list'])) {
                    foreach ($body['data']['list'] as $datum) {
                        if (mb_strpos($datum['name'], $name) !== false) {
                            $song_id = $datum['musicrid'] ?? '';
                            break;
                        }
                    }
                }
                if (! empty($song_id)) {
                    //get url
                    $get_url = 'http://antiserver.kuwo.cn/anti.s?type=convert_url&format=mp3&response=url&rid=' . $song_id;
                    $client_params['headers']['user-agent'] = 'okhttp/3.10.0';
                    $response2 = $client->request('GET', $get_url, $client_params);
                    if ($response2->getStatusCode() == 200) {
                        $song_url = $response2->getBody()->getContents();
                    }
                }
            }
        }

        return [$song_url, $song_size];
    }

    /**
     * kugou源.
     * @param $name
     * @param $ar_name
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return array
     */
    public function getKuGou($name, $ar_name)
    {
        $song_url = '';
        $song_size = 0;
        if (! empty($name)) {
            $key = urlencode(trim($name . ' ' . $ar_name));

            //search
            $search_url = 'http://songsearch.kugou.com/song_search_v2?keyword=' . $key . '&page=1';
            $headers = [
                'accept' => 'application/json, text/plain, */*',
                'accept-encoding' => 'gzip, deflate',
                'accept-language' => 'zh-CN,zh;q=0.9',
                'user-agent' => $this->chooseUserAgent('pc'),
                'X-Real-IP' => $this->chooseChinaIp(),
            ];
            $client = $this->clientFactory->create();
            $client_params['headers'] = $headers ?? [];
            $response = $client->request('GET', $search_url, $client_params);
            if ($response->getStatusCode() == 200) {
                $body = $response->getBody()->getContents();
                $body = json_decode($body, true);
                $song_id = '';
                if (isset($body['data']['lists'])) {
                    foreach ($body['data']['lists'] as $list) {
                        if (mb_strpos($list['SongName'], $name) !== false) {
                            $song_id = $list['FileHash'];
                            break;
                        }
                    }
                }
                if (! empty($song_id)) {
                    //get url
                    $get_url = 'http://trackercdn.kugou.com/i/v2/?key=' . md5($song_id . 'kgcloudv2') .
                        '&hash=' . $song_id . '&br=hq&appid=1005&pid=2&cmd=25&behavior=play';
                    $response2 = $client->request('GET', $get_url, $client_params);
                    if ($response2->getStatusCode() == 200) {
                        $body = $response2->getBody()->getContents();
                        $body = json_decode($body, true);
                        $url = $body['url'] ?? [];
                        $song_url = array_shift($url);
                        $song_size = $body['fileSize'] ?? 0;
                    }
                }
            }
        }

        return [$song_url, $song_size];
    }

    /**
     * migu源.
     * @param $name
     * @param $ar_name
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return array
     */
    public function getMiGu($name, $ar_name)
    {
        $song_url = '';
        $song_size = 0;
        if (! empty($name)) {
            $key = urlencode(trim($name . ' ' . $ar_name));

            $headers = [
                'accept' => 'application/json, text/plain, */*',
                'accept-encoding' => 'gzip, deflate',
                'accept-language' => 'zh-CN,zh;q=0.9',
                'user-agent' => $this->chooseUserAgent('pc'),
                'X-Real-IP' => $this->chooseChinaIp(),
            ];
            //search
            $search_url = 'http://m.music.migu.cn/migu/remoting/scr_search_tag?keyword=' . $key . '&type=2&rows=20&pgc=1';
            $client = $this->clientFactory->create();
            $client_params['headers'] = $headers ?? [];
            $response = $client->request('GET', $search_url, $client_params);
            if ($response->getStatusCode() == 200) {
                $body = $response->getBody()->getContents();
                $body = json_decode($body, true);
                $song_id = '';
                if (isset($body['musics'])) {
                    foreach ($body['musics'] as $music) {
                        if (mb_strpos($music['songName'], $name) !== false) {
                            $song_id = $music['copyrightId'];
                            break;
                        }
                    }
                }
                if (! empty($song_id)) {
//                    $type = [3,2,1];
                    $type = [2, 1];
                    foreach ($type as $item) {
                        $text = json_encode([
                            'copyrightId' => $song_id,
                            'type' => $item,
                        ]);
//                        $password = bin2hex('4ea5c508a6566e76240543f8feb06fd457777be39549c4016436afda65d2330e');
                        $key = hex2bin('a7c06c27da48afac469c15326daec278a2c643a01c1f1862f54bf9023f632e37');
                        $iv = hex2bin('8a2a4dc1967361d33a5b486c09e53b75');
                        $salt = hex2bin('9d45f545adeb6faf');

                        $ciphered = openssl_encrypt($text, 'aes-256-cbc', $key, 0, $iv);
                        $data = urlencode(base64_encode('Salted__' . $salt . base64_decode($ciphered)));
//                        $data = base64_encode($this->aesEncrypt(json_encode($text), $key, $iv));
//                        $rsa = new RSA();
//                        $rsa->loadKey('MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC8asrfSaoOb4je+DSmKdriQJKWVJ2oDZrs3wi5W67m3LwTB9QVR+cE3XWU21Nx+YBxS0yun8wDcjgQvYt625ZCcgin2ro/eOkNyUOTBIbuj9CvMnhUYiR61lC1f1IGbrSYYimqBVSjpifVufxtx/I3exReZosTByYp4Xwpb1+WAQIDAQAB');
//                        $secKey = $rsa->encrypt($password);
//                        $secKey = urlencode(base64_encode($secKey));
                        $secKey = 'fsVv3wRL%2FLsgNtYsBHBU8YZmwcrJ66QSAmJ53lD%2Bn%2FiXhW8hFCSI58rP1CJ57lWJ8cWsIObSQwkhd8XXhpU9bDXT%2FBt%2F6T3%2BNwqjcTeKb0WuezEs7ZnmzqNqxj6J%2B33vqN0Moso7H%2BBQGi4lY00vHTKUEGHWvtfs0Y9UIwBehs8%3D';

                        $get_url = 'http://music.migu.cn/v3/api/music/audioPlayer/getPlayInfo?dataType=2&data=' . $data . '&secKey=' . $secKey;
                        $headers = array_merge($headers, [
                            'origin' => 'http://music.migu.cn/',
                            'referer' => 'http://music.migu.cn/',
                        ]);
                        $client_params['headers'] = $headers ?? [];
                        $response2 = $client->request('GET', $get_url, $client_params);
                        if ($response2->getStatusCode() == 200) {
                            $body = $response2->getBody()->getContents();
                            $body = json_decode($body, true);
                            if (isset($body['returnCode']) && $body['returnCode'] == '000000' && isset($body['data'])) {
                                $song_url = $body['data']['playUrl'] ?? '';
                                if (! empty($song_url)) {
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }

        return [$song_url, $song_size];
    }

    /**
     * qq源.
     * @param $name
     * @param $ar_name
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @return array
     */
    public function getQQ($name, $ar_name)
    {
        $song_url = '';
        $song_size = 0;
        if (! empty($name)) {
            $key = urlencode(trim($name . ' ' . $ar_name));

            //search
            $search_url = 'https://c.y.qq.com/soso/fcgi-bin/client_search_cp?' .
                'ct=24&qqmusic_ver=1298&new_json=1&remoteplace=txt.yqq.center&' .
                'searchid=46804741196796149&t=0&aggr=1&cr=1&catZhida=1&lossless=0&' .
                'flag_qc=0&p=1&n=20&w=' . $key .
                '&g_tk=5381&jsonpCallback=MusicJsonCallback10005317669353331&loginUin=0&hostUin=0&' .
                'format=jsonp&inCharset=utf8&outCharset=utf-8&notice=0&platform=yqq&needNewCode=0';
            $headers = [
                'accept' => 'application/json, text/plain, */*',
                'accept-encoding' => 'gzip, deflate',
                'accept-language' => 'zh-CN,zh;q=0.9',
                'user-agent' => $this->chooseUserAgent('pc'),
                'X-Real-IP' => $this->chooseChinaIp(),
            ];
            $client = $this->clientFactory->create();
            $client_params['headers'] = $headers ?? [];
            $response = $client->request('GET', $search_url, $client_params);
            if ($response->getStatusCode() == 200) {
                $body = $response->getBody()->getContents();
                preg_match('/MusicJsonCallback10005317669353331\((.*)\)/', $body, $matches);
                if (isset($matches[1])) {
                    $body_array = json_decode($matches[1], true);
                } else {
                    $body_array = [];
                }
                $song_id = [];
                if (isset($body_array['data']['song']['list'])) {
                    foreach ($body_array['data']['song']['list'] as $song) {
                        if (mb_strpos($song['name'], $name) !== false) {
                            $song_id['song'] = $song['mid'];
                            $song_id['key'] = $song_id['file'] = $song['file']['media_mid'];
                            break;
                        }
                    }
                }
                if (count($song_id) > 0) {
                    //TODO:用cookie
                    $data['req_0'] = [
                        'module' => 'vkey.GetVkeyServer',
                        'method' => 'CgiGetVkey',
                        'param' => [
                            'guid' => '7332953645',
                            'loginflag' => 1,
                            'filename' => ['M500' . $song_id['file'] . '.mp3'],
                            //                            'filename' => ['M800' . $song_id['file'] . '.mp3'],
                            'songmid' => [$song_id['song']],
                            'songtype' => [0],
                            'uin' => '0',
                            'platform' => '20',
                        ],
                    ];
                    //get url
                    $get_url = 'https://u.y.qq.com/cgi-bin/musicu.fcg?data=' . urlencode(json_encode($data));
                    $response2 = $client->request('GET', $get_url, $client_params);
                    if ($response2->getStatusCode() == 200) {
                        $body = $response2->getBody()->getContents();
                        $body = json_decode($body, true);
                        $purl = $body['req_0']['data']['midurlinfo'][0]['purl'] ?? '';
                        if (! empty($purl)) {
                            $song_url = $body['req_0']['data']['sip'][0] . $purl;
                        }
                    }
                }
            }
        }
        return [$song_url, $song_size];
    }
}
