# A minimal Ubuntu without the libraries of the pair, so that `deps` proves its list. 26.04, as the
# PHP of 24.04 is 8.3. Composer only. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

FROM ubuntu:26.04@sha256:6a06111c3b0a8600feadb270aec771dc239bf407b25625f93f44ab00e11f4d3c

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
