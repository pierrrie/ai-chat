# Скопируйте в deploy.config.ps1 и заполните своими данными.
# deploy.config.ps1 в .gitignore — пароли не попадут в git.

$DeployConfig = @{
    # SSH: user@domain или user@IP
    SshHost = "user@example.com"

    # Корень сайта на сервере (автоопределение в deploy-remote.js, или укажите вручную)
    RemoteRoot = "/var/www/a0601335/data/www/draxter.ru"

    # Порт SSH (22 по умолчанию)
    SshPort = 22

    # Путь к приватному ключу (пусто = стандартный ~/.ssh/id_rsa)
    IdentityFile = ""
}
