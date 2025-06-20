<?php

// --- Загрузка конфигурации из .env файла ---
// Простая реализация загрузки .env без Composer.
// Для продакшн-окружения рекомендуется использовать vlucas/phpdotenv через Composer.
function loadEnv($path) {
    if (!file_exists($path)) {
        throw new Exception("Env file not found at " . $path);
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) {
            continue; // Пропускаем комментарии
        }
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
            putenv(sprintf('%s=%s', $key, $value));
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

try {
    loadEnv(__DIR__ . '/.env');
} catch (Exception $e) {
    error_log("Error loading .env file: " . $e->getMessage());
    die("Bot configuration error."); // Останавливаем выполнение, если .env не найден
}

// --- Получение переменных окружения ---
$botToken = getenv('TELEGRAM_BOT_TOKEN');
$managerEmail = getenv('MANAGER_EMAIL');
$conditionsFileUrl = getenv('CONDITIONS_FILE_URL');
$saveApplicationsToFile = filter_var(getenv('SAVE_APPLICATIONS_TO_FILE'), FILTER_VALIDATE_BOOLEAN);
$applicationsLogFile = getenv('APPLICATIONS_LOG_FILE');

// --- Константы для состояний пользователя ---
define('STATE_START', 'start');
define('STATE_AWAITING_NAME', 'awaiting_name');
define('STATE_AWAITING_PHONE', 'awaiting_phone');
define('STATE_AWAITING_COMMENT', 'awaiting_comment');
define('STATE_AWAITING_QUESTION', 'awaiting_question');

// --- Путь к файлу для хранения состояний пользователей ---
define('USER_STATES_FILE', __DIR__ . '/user_states.json');

// --- Вспомогательные функции ---

// Функция для отправки запросов к Telegram Bot API
function telegramApiRequest($method, $params = []) {
    global $botToken;
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        error_log("cURL Error: " . curl_error($ch));
    }
    curl_close($ch);
    // Проверяем HTTP-статус и декодируем ответ
    if ($http_code != 200) {
        error_log("Telegram API Error: HTTP Status {$http_code}, Response: {$response}");
    }
    return json_decode($response, true);
}

// Функция для получения состояния пользователя
function getUserState($chatId) {
    if (!file_exists(USER_STATES_FILE)) {
        return ['state' => STATE_START, 'data' => []];
    }
    $states = json_decode(file_get_contents(USER_STATES_FILE), true);
    return isset($states[$chatId]) ? $states[$chatId] : ['state' => STATE_START, 'data' => []];
}

// Функция для сохранения состояния пользователя
function saveUserState($chatId, $state, $data = []) {
    $states = [];
    if (file_exists(USER_STATES_FILE)) {
        $states = json_decode(file_get_contents(USER_STATES_FILE), true);
    }
    $states[$chatId] = ['state' => $state, 'data' => $data];
    file_put_contents(USER_STATES_FILE, json_encode($states, JSON_PRETTY_PRINT));
}

// Функция для удаления состояния пользователя
function clearUserState($chatId) {
    $states = [];
    if (file_exists(USER_STATES_FILE)) {
        $states = json_decode(file_get_contents(USER_STATES_FILE), true);
    }
    unset($states[$chatId]);
    file_put_contents(USER_STATES_FILE, json_encode($states, JSON_PRETTY_PRINT));
}

// Функция для отображения главного меню
function showMainMenu($chatId, $text = "Выберите действие:") {
    $keyboard = [
        ['text' => 'Отправить заявку'],
        ['text' => 'Условия покупки и доставки'],
        ['text' => 'Задать вопрос'],
    ];
    $replyMarkup = [
        'keyboard' => $keyboard,
        'resize_keyboard' => true,
        'one_time_keyboard' => false, // Постоянная клавиатура
        'is_persistent' => true // Явное указание на постоянство, если API поддерживает
    ];
    telegramApiRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => json_encode($replyMarkup)
    ]);
}

// Функция для отправки email менеджеру
function sendEmailToManager($subject, $body) {
    global $managerEmail;
    $headers = "From: Telegram Bot <no-reply@yourdomain.com>\r\n"; // Измените на реальный домен при необходимости
    $headers .= "Content-type: text/plain; charset=utf-8\r\n";
    return mail($managerEmail, $subject, $body, $headers);
}

// Функция для логирования заявки в файл
function logApplicationToFile($data) {
    global $applicationsLogFile;
    $logEntry = "[" . date("Y-m-d H:i:s") . "] " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($applicationsLogFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// --- Обработка входящих данных от Telegram ---
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';
    $contact = $message['contact'] ?? null; // Для кнопки "Поделиться номером"
    $userState = getUserState($chatId);
    $currentState = $userState['state'];
    $currentData = $userState['data'];

    // Обработка команды /start
    if (str_starts_with($text, '/start')) {
        $start_payload = substr($text, 7); // Получаем все после "/start "
        if (!empty($start_payload)) {
            $currentData['car_name'] = urldecode($start_payload); // Декодируем, если есть спецсимволы
            telegramApiRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "Здравствуйте! Вы выбрали автомобиль: *{$currentData['car_name']}*.\nЧем могу помочь?",
                'parse_mode' => 'Markdown'
            ]);
        } else {
            telegramApiRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => "Здравствуйте! Я бот для сбора заявок на покупку автомобилей."
            ]);
        }
        saveUserState($chatId, STATE_START, $currentData); // Сохраняем имя авто в сессию
        showMainMenu($chatId);
        exit; // Завершаем выполнение, т.к. это инициализация
    }

    // --- Обработка команд из главного меню ---
    switch ($text) {
        case 'Отправить заявку':
            saveUserState($chatId, STATE_AWAITING_NAME, $currentData); // Переходим в состояние ожидания имени
            telegramApiRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Пожалуйста, введите ваше имя:'
            ]);
            break;

        case 'Условия покупки и доставки':
            saveUserState($chatId, STATE_START, $currentData); // Возвращаемся в начальное состояние
            telegramApiRequest('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
            $conditionsText = file_get_contents($conditionsFileUrl);
            if ($conditionsText === false) {
                telegramApiRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => 'Извините, не удалось загрузить информацию об условиях. Пожалуйста, попробуйте позже или свяжитесь с нами напрямую.'
                ]);
            } else {
                telegramApiRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $conditionsText
                ]);
            }
            showMainMenu($chatId, 'Могу ли я еще чем-то помочь?');
            break;

        case 'Задать вопрос':
            saveUserState($chatId, STATE_AWAITING_QUESTION, $currentData); // Переходим в состояние ожидания вопроса
            telegramApiRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Пожалуйста, введите ваш вопрос:'
            ]);
            break;

        default:
            // --- Обработка состояний пользователя ---
            switch ($currentState) {
                case STATE_AWAITING_NAME:
                    $currentData['name'] = $text;
                    saveUserState($chatId, STATE_AWAITING_PHONE, $currentData);
                    telegramApiRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => 'Теперь введите ваш номер телефона или используйте кнопку "Поделиться номером":',
                        'reply_markup' => json_encode([
                            'keyboard' => [[['text' => 'Поделиться номером', 'request_contact' => true]]],
                            'resize_keyboard' => true,
                            'one_time_keyboard' => true // Эта клавиатура для одноразового использования
                        ])
                    ]);
                    break;

                case STATE_AWAITING_PHONE:
                    // Если пользователь отправил контакт через кнопку
                    if ($contact && isset($contact['phone_number'])) {
                        $currentData['phone'] = $contact['phone_number'];
                    } else {
                        // Если пользователь ввел номер вручную
                        $currentData['phone'] = $text;
                    }
                    saveUserState($chatId, STATE_AWAITING_COMMENT, $currentData);
                    telegramApiRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => 'Хотите добавить комментарий к заявке? (Необязательно):',
                        'reply_markup' => json_encode([
                            'remove_keyboard' => true // Убираем клавиатуру с кнопкой "Поделиться номером"
                        ])
                    ]);
                    break;

                case STATE_AWAITING_COMMENT:
                    $currentData['comment'] = $text;
                    // --- Отправка заявки ---
                    $carName = $currentData['car_name'] ?? 'Не указано';
                    $applicationBody = "Новая заявка на автомобиль:\n";
                    $applicationBody .= "Автомобиль: " . $carName . "\n";
                    $applicationBody .= "Имя: " . ($currentData['name'] ?? 'Не указано') . "\n";
                    $applicationBody .= "Телефон: " . ($currentData['phone'] ?? 'Не указано') . "\n";
                    $applicationBody .= "Комментарий: " . ($currentData['comment'] ?? 'Нет') . "\n";
                    $applicationBody .= "ID Чата: " . $chatId . "\n";

                    if (sendEmailToManager("Новая заявка на авто: {$carName}", $applicationBody)) {
                        $confirmationText = "Спасибо! Ваша заявка принята и уже направлена менеджеру. Он свяжется с вами в ближайшее время.";
                        if ($carName !== 'Не указано') {
                            $confirmationText = "Отлично! Ваша заявка по автомобилю *{$carName}* принята. Ожидайте звонка от нашего менеджера!";
                        }
                        telegramApiRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => $confirmationText,
                            'parse_mode' => 'Markdown'
                        ]);
                        // Логирование заявки, если опция включена
                        if ($saveApplicationsToFile) {
                            logApplicationToFile($currentData);
                        }
                        clearUserState($chatId); // Очищаем состояние после успешной отправки
                        showMainMenu($chatId, 'Могу ли я еще чем-то помочь?');
                    } else {
                        telegramApiRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => 'Произошла ошибка при отправке заявки. Пожалуйста, попробуйте позже или свяжитесь с нами напрямую.'
                        ]);
                        showMainMenu($chatId);
                    }
                    break;

                case STATE_AWAITING_QUESTION:
                    $question = $text;
                    $subject = "Новый вопрос от пользователя Telegram (ID: {$chatId})";
                    $body = "Пользователь ID: {$chatId}\nИмя пользователя: " . ($message['from']['first_name'] ?? 'Неизвестно') . "\n\nВопрос:\n{$question}";

                    if (sendEmailToManager($subject, $body)) {
                        telegramApiRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => 'Ваш вопрос получен! Менеджер рассмотрит его и ответит вам по электронной почте в ближайшее время.'
                        ]);
                        clearUserState($chatId); // Очищаем состояние после отправки вопроса
                        showMainMenu($chatId, 'Могу ли я еще чем-то помочь?');
                    } else {
                        telegramApiRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => 'Произошла ошибка при отправке вашего вопроса. Пожалуйста, попробуйте позже.'
                        ]);
                        showMainMenu($chatId);
                    }
                    break;

                case STATE_START:
                default:
                    // Если пользователь вводит что-то, когда ожидается выбор из меню
                    telegramApiRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => 'Извините, я вас не понял. Пожалуйста, используйте кнопки меню.'
                    ]);
                    showMainMenu($chatId);
                    break;
            }
            break;
    }
}
?>