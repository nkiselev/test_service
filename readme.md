Небольшой пример кода сервиса, реализующего бизнес-логику.

В данном случае мы имеем пакет для ларавеля, который мониторит все изменения моделей и отсылает их в кафку для реализации event-driven architecture

Тут только консьюмер который случает кафку и по ивентам восстанавливает состояние модели