QA_DOCKER_IMAGE=jakzal/phpqa:alpine
QA_DOCKER_COMMAND=docker run -it --rm -v /tmp/tmp-phpqa:/tmp -v "$(shell pwd):/project" -w /project ${QA_DOCKER_IMAGE}

dist: cs phpstan

cs:
	sh -c "${QA_DOCKER_COMMAND} php-cs-fixer fix --diff -vvv"

phpstan:
	sh -c "${QA_DOCKER_COMMAND} phpstan analyse"

