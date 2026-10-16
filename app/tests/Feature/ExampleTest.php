<?php

/*
 * Substitui o ExampleTest que o esqueleto do Laravel cria: esse fazia
 * `GET /` e esperava 200, mas esta aplicação é só uma API — não tem
 * página inicial (dava 500). O que interessa garantir é que a
 * aplicação arranca, e para isso existe o endpoint de saúde /up.
 */
it('a aplicação arranca (endpoint de saúde /up)', function () {
    $this->get('/up')->assertOk();
});
