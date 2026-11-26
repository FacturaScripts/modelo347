<!-- Fuente: https://sede.agenciatributaria.gob.es/Sede/ayuda/consultas-informaticas/declaraciones-informativas-ayuda-tecnica/numero-justificante-debe-ser-cumplimentado-correcto.html -->

Agencia Tributaria: Cómo cumplimentar el número de justificante de un modelo de declaración informativa

 [Saltar al contenido principal](#acc-main) 

 [Logotipo del Gobierno de EspañaLogotipo Organismo](/Sede/inicio.html)

 Menú móvil[Menu](#)

[Inicio](/Sede/inicio.html)
[Ayuda](/Sede/ayuda.html)
[Consultas informáticas](/Sede/ayuda/consultas-informaticas.html)
[Declaraciones Informativas - Ayuda técnica](/Sede/ayuda/consultas-informaticas/declaraciones-informativas-ayuda-tecnica.html)

 Cómo cumplimentar el número de justificante de un modelo de declaración informativa
El número de justificante es un campo obligatorio en las declaraciones informativas. En función de cómo se está presentando la declaración, será necesario revisar cuál es el dato informado en este campo.

Si se está realizando la presentación desde el formulario, "Ejercicio 202X. Presentación (hasta 40.000 registros)", se admite que el número de justificante que se ha informado en el fichero sea un número que comience por los dígitos del modelo y el resto de posiciones sean ceros. Por ejemplo, 1820000000000. En este caso no se tendrá ningún problema ni al importar el fichero, ni al presentar.

Si se está utilizando la opción de envío mediante fichero, "Ejercicio 202X. Presentación mediante fichero", el justificante debe comenzar por el número de modelo, y para el resto de campos se admiten cualquier combinación numérica excepto aquella en la que el resto de posiciones sean todas cero. Para leer el fichero correctamente debería aparecer 1820000000001 o cualquier otra variación, por ejemplo, 1822025000001 para el ejercicio 2025.

Para resolver el error, tendrás que editar el fichero. El campo "Número de justificante" se localiza siempre en el registro de tipo 1, Declarante, que coincide con la primera línea del fichero y normalmente entre las posiciones 108 y 120, aunque lo recomendable es consultar el diseño de registro actualizado para el modelo de declaración informativa concreto y verificar las posiciones que debes revisar.

En caso de presentar una declaración complementaria o sustitutiva mediante fichero, el número de justificante de la nueva declaración debe ser diferente al justificante de la declaración a la que complementa o sustituye, además, también se debe hacer referencia al número de justificante de la declaración anterior y que se desea complementar o sustituir.

Página actualizada: 09/enero/2026
