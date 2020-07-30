<?php
/**
 * This file is part of the TelegramBot package.
 *
 * @author Leonid Sheikman <leonid@sheikman.ru> inspired by Avtandil Kikabidze aka LONGMAN
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\AdminCommands;

use PDOException;
use Longman\TelegramBot\Commands\AdminCommand;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\TelegramLog;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\SurveysDB;


/**
 * User "/sendquestion" command
 *
 * Simply echo the input back to the user.
 */
class SendQuestionCommand extends AdminCommand
{
    /**
     * @var string
     */
    protected $name = 'sendquestion';

    /**
     * @var string
     */
    protected $description = 'Отправка очередного случайного вопроса';

    /**
     * @var string
     */
    protected $usage = '/sendquestion';

    /**
     * @var string
     */
    protected $version = '1.0.0';

    /**
     * @var bool
     */
    protected $need_mysql = true;

    /**
     * @var bool
     */
    protected $private_only = true;

    /**
     * Conversation Object
     *
     * @var \Longman\TelegramBot\Conversation
     */
    protected $conversation;

    /**
     * Command execute method
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute()
    {
        $message = $this->getMessage();

        $chat    = $message->getChat();
        $user    = $message->getFrom();
        $text    = trim($message->getText(true));
        $chat_id = $chat->getId();
        $user_id = $user->getId();

        //Preparing Response
        $data = [
            'chat_id' => $chat_id,
        ];

        //Conversation start
        $this->conversation = new Conversation($user_id, $chat_id, $this->getName());

        $notes = &$this->conversation->notes;
        if(!is_array($notes)) $notes = [];

        //cache data from the tracking session if any
        $state = 0;
        if (isset($notes['state'])) $state = $notes['state'];

        $result = Request::emptyResponse();

        TelegramLog::debug('state: ' . $state);
        TelegramLog::debug('text: ' . $text);

        //State machine
        //Entrypoint of the machine state if given by the track
        //Every time a step is achieved the track is updated
        switch ($state) {
            case 0:
                if ($text === '') {
                    $notes['state'] = 0;

                    //! this temp string, later i'll replace it with a questions from table
                    $data['text']   = 'Question 1..... Lorem ipsum.......' . PHP_EOL . PHP_EOL . 'Answers:' . PHP_EOL . '1. Answer1' . PHP_EOL . '2. Answer2' . PHP_EOL . '3. Answer3' . PHP_EOL . '4. Answer4' . PHP_EOL . '5. Answer5' . PHP_EOL . '6. Answer6';

                    $notes['transcript']['q'][] = $data['text'];
                    $this->conversation->update();

                    $result = Request::sendMessage($data);
                }
                $text = '';

            // no break
            case 1:
                if ($text === '' || !is_numeric($text)) {
                    $notes['state'] = 1;
                    $this->conversation->update();

                    $keyboard = new Keyboard(
                        ['1', '2', '3'],
                        ['4', '5', '6']
                    );

                    $data['reply_markup'] = $keyboard
                        ->setResizeKeyboard(true)
                        ->setOneTimeKeyboard(true)
                        ->setSelective(true);

                    $data['text'] = 'Select answer:';
                    if ($text !== '') {
                        $data['text'] = 'Select answer...Выберите вариант ответа, нажав один из предложенных:';
                    }

                    $result = Request::sendMessage($data);
                    break;
                }

                $notes['transcript']['a'][] = $text;
                $text                       = '';

            // no break
            case 2:
                $notes['state'] = 2;
                $this->conversation->update();

                TelegramLog::debug('notes[state]: ' . $notes['state']);
                TelegramLog::debug('notes[transcript]: ' . print_r($notes['transcript'],1));

                try {
                    if (!DB::isDbConnected()) {
                        $data['text'] = 'Ошибка. Сообщите об этом разработчику: ' . PHP_EOL . 'no DB connection';
                        TelegramLog::error($data['text']);
                        //throw new TelegramException($data['text']);
                        break;
                    } else {
                        $fields = ['transcript' => json_encode($notes['transcript'], JSON_UNESCAPED_UNICODE)];
                        $where = ['user_id' => $user_id, 'chat_id' => $chat_id];
                        TelegramLog::debug('fields: ' . print_r($fields,1));
                        TelegramLog::debug('where: ' . print_r($where,1));
                        if (!SurveysDB::updateRespondent($fields, $where)) {
                            $data['text'] = 'Ошибка. Сообщите об этом разработчику:' . PHP_EOL . 'update error';
                        } else {
                            unset($notes['state']);
                            $out_text = 'Good! See you. Ваш ответ принят. До следующей встречи!' . PHP_EOL;
                            $data['reply_markup']   = Keyboard::remove(['selective' => true]);
                            //$data['caption']      = $out_text;
                            $data['text']           = $out_text;
                        }
                        $text = '';
                        $this->conversation->stop();
                    }
                } catch (Exception $e) {
                    TelegramLog::error($e->getMessage());
                    $data['text'] = 'Ошибка... Сообщите об этом разработчику:' . PHP_EOL . $e->getMessage();
                    $this->conversation->stop();
                    //throw new TelegramException($e->getMessage());
                }

                //$result = Request::sendPhoto($data);
                TelegramLog::debug('1 result: ' . $result);
                $result = Request::sendMessage($data);
                break;
        }

        TelegramLog::debug('2 result: ' . $result);
        return $result;
    }










    /*
        //$message = $this->getMessage();

        //$chat_id = $message->getChat()->getId();
        //$text    = trim($message->getText(true));
        $results = SurveysDB::selectAllRespondents();

        //$chat_id = '496865698';
        $text    = $results ? print_r($results,1) : 'empty answer from db';

        TelegramLog::debug('results: ' . $text);

        if ($text === '') {
            $text = 'Command usage: ' . $this->getUsage();
        }

        foreach ($results as $k => $v) {
            $data = [
                'chat_id' => $v['chat_id'],
                'text'    => '$k: ' . $k . PHP_EOL . $text,
            ];
            Request::sendMessage($data);


        }

        return $result;
    */
}
