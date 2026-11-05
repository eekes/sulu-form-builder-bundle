module.exports = {
    testEnvironment: 'node',
    testMatch: ['<rootDir>/**/tests/**/*.test.js'],
    moduleNameMapper: {
        '\\.(css|scss)$': '<rootDir>/tests/styleMock.js',
    },
};
