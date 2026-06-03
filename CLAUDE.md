# folder_protection — notas para Claude

## Ambiente

- Container: `nextcloud-app` | URL externa: `http://localhost:8080`
- App montada via bind mount: `/home/ricardo/nextcloud-dev/apps/` → `/var/www/html/custom_apps/`
- Alterações PHP são imediatas, sem reiniciar o container

## Correr testes

As dependências (PHPUnit) estão em `vendor/` — instalar com `composer install` se ausentes.

Os testes correm **dentro do container** (o namespace `OCP\` é carregado pelos autoloaders do Nextcloud).

```bash
# Unitários
docker exec nextcloud-app php /var/www/html/custom_apps/folder_protection/vendor/bin/phpunit \
  -c /var/www/html/custom_apps/folder_protection/phpunit.xml

# Integração (requerem o servidor activo; porta interna 80)
docker exec \
  -e FP_TEST_PASSWORD=yura \
  -e FP_TEST_BASE_URL=http://localhost \
  nextcloud-app php /var/www/html/custom_apps/folder_protection/vendor/bin/phpunit \
  -c /var/www/html/custom_apps/folder_protection/phpunit.integration.xml
```

## Formato de paths na DB

Paths guardados **sem username**: `/files/normal` (não `/files/ncadmin/normal`).

## Limpar cache

```bash
docker exec nextcloud-app php /var/www/html/occ folder-protection:clear-notifications
docker exec nextcloud-app php /var/www/html/occ maintenance:repair --quiet
```
