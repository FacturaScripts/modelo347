# 0003. El texto de los emails es una notificación editable por el usuario

- Fecha: 2026-11-25
- Commits: `16da290`
- Estado: vigente

## Contexto

La carta se componía con bloques fijos en dos workers, uno para clientes y otro para proveedores, y el texto de proveedores era el de clientes.

## Decisión

`Init::update()` crea las notificaciones `modelo347-customers` y `modelo347-suppliers`. El worker compone el email con `MailNotifier::getText()` y las etiquetas `{name}`, `{cifnif}`, `{email}`, `{year}`, `{amount}`, `{t1}`..`{t4}`, `{total}` y `{deadline}`. Si ya existen, solo se reescribe el asunto o el cuerpo vacío o igual a su clave de traducción, para no pisar los cambios del usuario. El texto de proveedores habla de compras.

## Consecuencias

- Si la notificación no existe o está desactivada no se envía nada (un único aviso en el log).
- Una etiqueta nueva debe añadirse en el worker y en el texto por defecto de las traducciones.
