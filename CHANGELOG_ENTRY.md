## [1.2.1] — 2026-07-13

### Corrigido
- **`compact()` dentro de arrow functions (`fn`) lançava `ErrorException: Undefined variable`** em `remember()`, `set()`, `lock()` e `forget()`. O PHP decide o que uma `fn` captura por análise estática do corpo — `compact('namespace', ...)` não conta como uso directo de `$namespace`, por isso a variável nunca era capturada e o `compact()` falhava em runtime. Substituído por arrays literais (`['namespace' => $namespace, ...]`) nos quatro métodos afectados.
- **`parseDotKey()` cortava sempre no primeiro ponto**, impedindo namespaces com mais de um nível (`format.date_time`, `format.currency`). Uma chamada como `setting('format.date_time.date')` produzia `namespace='format'` + `key='date_time.date'`, que nunca coincidia com um registo gravado como `namespace='format.date_time'` + `key='date'` — resultando em `null` silencioso. Trocado `strpos()` por `strrpos()`: agora corta no **último** ponto, permitindo namespaces multi-nível com key sempre simples.

### Nota de migração
Se algum ponto do teu código dependia do comportamento antigo (namespace de um nível + key com pontos, ex: `setting('mail.smtp.host')` esperando `namespace='mail'`), o resultado agora é `namespace='mail.smtp'` + `key='host'`. Revê chamadas com 3+ segmentos separados por ponto antes de actualizar.
