-- wrk script for pages with a session: args[1] is a file of Cookie header values, one session per
-- line; args[2] the number of wrk threads. Each thread takes its own slice of the sessions and
-- cycles through it, so no two requests in flight share a session (file sessions lock, or don't).
local threads = 0

function setup(thread)
    thread:set("id", threads)
    threads = threads + 1
end

function init(args)
    local all = {}
    for line in io.lines(args[1]) do
        if line ~= "" then all[#all + 1] = line end
    end
    local per = math.floor(#all / tonumber(args[2]))
    cookies = {}
    for i = 1, per do
        cookies[i] = all[id * per + i]
    end
    n = 0
end

function request()
    n = n + 1
    return wrk.format(nil, nil, { Cookie = cookies[n % #cookies + 1] })
end
