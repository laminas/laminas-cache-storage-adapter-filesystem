#!/bin/bash -l

if [[ -z "${GITHUB_BASE_REF}" ]]; then
    echo "Environment variable \"GITHUB_BASE_REF\" does not exist."

    exit 0
fi

BRANCH_REGEX="[0-9]+\.[0-9]+\.x"

if ! [[ "${GITHUB_BASE_REF}" =~ ${BRANCH_REGEX} ]]; then
    echo "Environment variable \"GITHUB_BASE_REF\" does not match expectations."
    echo "Must match ${BRANCH_REGEX}";

    exit 0
fi

COMPOSER_ROOT_VERSION=$(echo ${GITHUB_BASE_REF} | sed 's/\.x/\.99/g')

echo "Determined composer root version as \"${COMPOSER_ROOT_VERSION}\"."

if [[ true = "${GITHUB_ACTIONS}" ]]; then
    echo "Setting COMPOSER_ROOT_VERSION environment variable to \"${COMPOSER_ROOT_VERSION}\"."
    if [ ! -w "${GITHUB_ENV}" ]; then
        echo "Missing GITHUB_ENV environment variable. Cannot store COMPOSER_ROOT_VERSION to be available within the current check."
        exit 1
    fi
    echo "COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION}" >> "${GITHUB_ENV}"
fi
