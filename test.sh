docker-compose -p "$(basename $PWD)-test" -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from test --attach test
EXITCODE=$?
docker-compose -p "$(basename $PWD)-test" -f docker-compose.test.yml down -v

exit $EXITCODE
