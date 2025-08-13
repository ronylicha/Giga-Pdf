#!/bin/bash

# Script to run tests with various options

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Default values
PARALLEL=false
PROCESSES=4
COVERAGE=false
FILTER=""

# Parse command line options
while [[ $# -gt 0 ]]; do
    case $1 in
        -p|--parallel)
            PARALLEL=true
            shift
            ;;
        --processes)
            PROCESSES="$2"
            shift 2
            ;;
        -c|--coverage)
            COVERAGE=true
            shift
            ;;
        -f|--filter)
            FILTER="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [options]"
            echo "Options:"
            echo "  -p, --parallel       Run tests in parallel"
            echo "  --processes NUM      Number of parallel processes (default: 4)"
            echo "  -c, --coverage       Generate code coverage report"
            echo "  -f, --filter FILTER  Filter tests by name"
            echo "  -h, --help           Show this help message"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            exit 1
            ;;
    esac
done

echo -e "${GREEN}Running Giga-PDF Tests${NC}"
echo "========================="

# Build the test command
CMD="php artisan test"

if [ "$PARALLEL" = true ]; then
    CMD="$CMD --parallel --processes=$PROCESSES"
    echo -e "${YELLOW}Running in parallel with $PROCESSES processes${NC}"
fi

if [ "$COVERAGE" = true ]; then
    CMD="$CMD --coverage"
    echo -e "${YELLOW}Generating coverage report${NC}"
fi

if [ ! -z "$FILTER" ]; then
    CMD="$CMD --filter=\"$FILTER\""
    echo -e "${YELLOW}Filtering tests: $FILTER${NC}"
fi

# Run the tests
echo -e "${GREEN}Executing: $CMD${NC}"
eval $CMD

# Check the exit code
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed!${NC}"
else
    echo -e "${RED}✗ Some tests failed${NC}"
    exit 1
fi