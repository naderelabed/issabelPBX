��    1      �  C   ,      8  �  9     �  
   �     �     �  7   �     5  	   C     M     �     �     �  $     '   '     O     g     m  .   v     �  
   �     �     �     �  
   �     �     �  &   �  	   "  0   ,     ]  -   c     �  o   �  �   	     �	  1   �	     �	     �	     �	     �	  :   
     >
     M
  P   V
  &   �
  	   �
     �
     �
  8  �
  �  %  2     !   5  !   W  .   y  [   �          !  P  8  0   �  )   �  !   �  D     Y   K  -   �  	   �     �  c   �     V     \     k  %   ~     �     �  *   �     �  :   �     0  U   >  	   �  ^   �  	   �  �     �   �     �  U   �     �  $   �          :  i   W  %   �     �  �   �  M   �      �     �                        ,              &                   	   '                     0                   *       (         #   1                                   "   )      /                          !       $      -      
       +   .   %       A Lookup Source let you specify a source for resolving numeric CallerIDs of incoming calls, you can then link an Inbound route to a specific CID source. This way you will have more detailed CDR reports with information taken directly from your CRM. You can also install the phonebook module to have a small number <-> name association. Pay attention, name lookup may slow down your PBX Add CID Lookup Source Add Source CID Lookup Source Cache results: Checking for cidlookup field in core's incoming table.. Database name Database: Decide whether or not cache the results to astDB; it will overwrite present values. It does not affect Internal source behavior Delete CID Lookup source ERROR: failed:  Edit Source Enter a description for this source. FATAL: failed to transform old routes:  Host name or IP address Host: Internal Migrating channel routing to Zap DID routing.. MySQL MySQL Host MySQL Password MySQL Username None Not Needed Not yet implemented OK Password to use in HTTP authentication Password: Path of the file to GET<br/>e.g.: /cidlookup.php Path: Port HTTP server is listening at (default 80) Port: Query string, special token '[NUMBER]' will be replaced with caller number<br/>e.g.: number=[NUMBER]&source=crm Query, special token '[NUMBER]' will be replaced with caller number<br/>e.g.: SELECT name FROM phonebook WHERE number LIKE '%[NUMBER]%' Query: Removing deprecated channel field from incoming.. Source Source Description: Source type: Source: %s (id %s) Sources can be added in Caller Name Lookup Sources section Submit Changes SugarCRM There are %s DIDs using this source that will no longer have lookups if deleted. Username to use in HTTP authentication Username: not present removed Project-Id-Version: 1.3
Report-Msgid-Bugs-To: 
POT-Creation-Date: 2015-05-05 21:35-0400
PO-Revision-Date: 2011-04-14 17:00+0100
Last-Translator: Alexander Kozyrev <ceo@postmet.com>
Language-Team: Russian <faq@postmet.com>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit
 Сервис поиска по Caller ID поможет превращать поступающие звонки из номеров в узнаваемые имена или названия, которые затем можно сопоставлять со сценариями входящей маршрутизации для каждого. Ещё одно преимущество - более понятный и детальный список входящих в отчетах о звонках, с добавлением информации прямо из вашей программы CRM. Также можно инсталлировать и использовать модуль Телефонная книга для сопоставления коротких номеров и имен. Внимание! Сервис поиска может затормаживать быстродействие вашей ИП-АТС, если её ресурсы скромны. Добавить источник поиска CID Добавить Источник Источник поиска CID Кэшированные результаты: Проверка поля cidlookup в структуре таблицы входящих.. Имя базы данных База данных: Определитесь, нужно ли кэшировать результаты запросов в astDB; результаты кэш могут не всегда совпадать с действительными. Не влияет на поведение и достоверность внутренних источников. Удалить источник поиска CID ОШИБКА: не получилось:  Изменить источник Создайте краткое описание источника. НЕ СУДЬБА: ошибка при переносе старых маршрутов:  Имя хоста или его IP адрес Хост: Внутренний Перенос маршрутизации каналов в маршрутизацию по Zap DID MySQL Хост MySQL Пароль MySQL Имя пользователя MySQL Нет Не надобности Пока не обеспечивается ОК Пароль для аутентификации по HTTP Пароль: Путь к файлу для GET запроса<br/>например: /cidlookup.php Путь: HTTP порт сервера, слушающего запросы (по умолчанию 80) Порт: Переменная запроса, содержащая '[NUMBER]', которая получает значение Caller  ID <br/>например: number=[NUMBER]&source=crm Строка запроса, содержащая '[NUMBER]', которая получает значение Caller ID <br/>например: SELECT name FROM phonebook WHERE number LIKE '%[NUMBER]%' Запрос: Удаление устаревшего поля канала из входящих.. Источник Описание источника: Тип источника: Источник: %s (id %s) Источник может быть добавлен в секцию Сервис поиска Caller ID Применить изменения SugarCRM Следующие номера DID %s не смогут больше использовать этот источник если он будет удалён. Имя пользователя для аутентификации по HTTP Имя пользователя: отсутствует удалено 